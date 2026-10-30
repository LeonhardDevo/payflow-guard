<?php

declare(strict_types=1);

use App\Billing\BillingException;
use App\Billing\PlanCatalog;
use App\Billing\PlanLimits;
use App\Billing\StripeGateway;
use App\Enums\BillingInterval;
use App\Enums\WorkspaceRole;
use App\Models\AiQuery;
use App\Models\Document;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;

it('renders the billing page with plans, usage and no subscription', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    Document::factory()->count(2)->create(['workspace_id' => $workspace->id, 'size_bytes' => 3 * 1048576]);
    WorkspaceInvitation::factory()->create(['workspace_id' => $workspace->id]);

    $this->get(route('settings.billing', ['checkout' => 'cancelled']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/Billing', false)
            ->where('subscription', null)
            ->where('currentPlan', 'free')
            ->where('checkout', 'cancelled')
            ->has('plans', 4)
            ->where('plans.0.key', 'free')
            ->where('plans.2.highlighted', true)
            ->where('plans.2.currency', 'USD')
            ->where('usage.seats', ['used' => 2, 'limit' => 2])
            ->where('usage.documents', ['used' => 2, 'limit' => 3])
            ->where('usage.storage_mb', ['used' => 6, 'limit' => 100])
            ->where('usage.ai_queries_monthly', ['used' => 0, 'limit' => 30]));
});

it('localizes plans with correct Russian plurals', function (): void {
    App::setLocale('ru');
    $plans = collect(app(PlanCatalog::class)->all())->keyBy('key');

    expect($plans['free']['name'])->toBe('Бесплатный')
        ->and($plans['free']['features'])->toContain('2 места в команде')
        ->and($plans['free']['features'])->toContain('3 документа в базе знаний')
        ->and($plans['starter']['features'])->toContain('3 места в команде')
        ->and($plans['pro']['features'])->toContain('10 ГБ для файлов')
        ->and($plans['business']['features'])->toContain('50 мест в команде');

    App::setLocale('en');
    expect(app(PlanCatalog::class)->toContract('free')['features'])->toContain('2 team seats');
});

it('renders the public pricing page', function (): void {
    $this->get(route('pricing'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('marketing/Pricing', false)->has('plans', 4));
});

it('blocks uploads above the documents limit (plan.limit middleware)', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    config(['billing.plans.free.limits.documents' => 1]);
    Document::factory()->create(['workspace_id' => $workspace->id]);

    $this->post(route('knowledge.store'), [])->assertRedirect()->assertSessionHas('error');
    $this->postJson(route('knowledge.store'), [])
        ->assertStatus(402)
        ->assertJson(['limit' => 'documents', 'upgrade' => route('settings.billing')]);
});

it('counts monthly AI answers and unlimited plans', function (): void {
    $workspace = Workspace::factory()->create();
    AiQuery::query()->create(['workspace_id' => $workspace->id, 'channel' => 'web', 'question' => 'q', 'refused' => false]);
    $old = AiQuery::query()->create(['workspace_id' => $workspace->id, 'channel' => 'web', 'question' => 'old', 'refused' => false]);
    DB::table('ai_queries')->where('id', $old->id)->update(['created_at' => now()->subMonths(2)]);

    $limits = app(PlanLimits::class);
    expect($limits->used($workspace, 'ai_queries_monthly'))->toBe(1)
        ->and($limits->allows($workspace, 'ai_queries_monthly'))->toBeTrue();

    config(['billing.plans.free.limits.ai_queries_monthly' => null]);
    expect($limits->limit($workspace, 'ai_queries_monthly'))->toBeNull()
        ->and($limits->allows($workspace, 'ai_queries_monthly', 1_000_000))->toBeTrue();
});

function fakeStripeHttp(ArrayObject $captured): ClientInterface
{
    return new class($captured) implements ClientInterface
    {
        public function __construct(private ArrayObject $captured) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $this->captured->append(['method' => $method, 'url' => $absUrl, 'params' => $params, 'headers' => $headers]);
            $path = (string) parse_url($absUrl, PHP_URL_PATH);

            $body = match (true) {
                $path === '/v1/prices' => ['object' => 'list', 'url' => '/v1/prices', 'has_more' => false, 'data' => [
                    ['id' => 'price_pro_m', 'object' => 'price', 'active' => true, 'currency' => 'usd', 'unit_amount' => 4900, 'lookup_key' => 'testcat_pro_month_usd'],
                ]],
                $path === '/v1/customers' => ['id' => 'cus_new_1', 'object' => 'customer'],
                $path === '/v1/billing_portal/configurations' => ['object' => 'list', 'url' => $path, 'has_more' => false, 'data' => [
                    ['id' => 'bpc_other', 'object' => 'billing_portal.configuration', 'metadata' => ['catalog' => 'another']],
                    ['id' => 'bpc_ours', 'object' => 'billing_portal.configuration', 'metadata' => ['catalog' => 'testcat']],
                ]],
                $path === '/v1/billing_portal/sessions' => ['id' => 'bps_1', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/p/session_1'],
                default => ['id' => 'cs_live_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_live_1'],
            };

            return [json_encode($body), 200, []];
        }
    };
}

it('creates a Stripe Checkout Session with customer, metadata, trial and tax-ID collection', function (): void {
    config([
        'billing.driver' => 'stripe',
        'billing.stripe.secret' => 'sk_test_123',
        'billing.stripe.catalog' => 'testcat',
        'billing.stripe.automatic_tax' => true,
        'billing.trial_days' => 14,
    ]);

    $captured = new ArrayObject;
    ApiRequestor::setHttpClient(fakeStripeHttp($captured));
    app()->forgetInstance(StripeClient::class);

    try {
        [$user, $workspace] = $this->signInMember(WorkspaceRole::Owner);

        $response = $this->post(route('settings.billing.checkout'), ['plan' => 'pro', 'interval' => 'month'], ['X-Inertia' => 'true']);
        $response->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://checkout.stripe.com/c/pay/cs_live_1');

        $calls = collect($captured->getArrayCopy());
        $lookup = $calls->first(fn (array $call): bool => parse_url($call['url'], PHP_URL_PATH) === '/v1/prices');
        $customer = $calls->first(fn (array $call): bool => parse_url($call['url'], PHP_URL_PATH) === '/v1/customers');
        $checkout = $calls->first(fn (array $call): bool => parse_url($call['url'], PHP_URL_PATH) === '/v1/checkout/sessions');

        expect($lookup['params']['lookup_keys'])->toBe(['testcat_pro_month_usd'])
            ->and($customer['params']['metadata']['workspace_id'])->toBe((string) $workspace->id)
            ->and($customer['params']['email'])->toBe($user->email)
            ->and(implode("\n", $customer['headers']))->toContain('Idempotency-Key: customer-testcat-workspace-'.$workspace->id);

        $params = $checkout['params'];
        expect($params['mode'])->toBe('subscription')
            ->and($params['customer'])->toBe('cus_new_1')
            ->and($params['line_items'][0]['price'])->toBe('price_pro_m')
            ->and($params['metadata'])->toBe(['workspace_id' => (string) $workspace->id, 'plan' => 'pro', 'interval' => 'month', 'currency' => 'USD'])
            ->and($params['subscription_data']['metadata'])->toBe($params['metadata'])
            ->and($params['subscription_data']['trial_period_days'])->toBe(14)
            ->and($params['tax_id_collection'])->toBe(['enabled' => 'true'])
            ->and($params['automatic_tax'])->toBe(['enabled' => 'true'])
            ->and($params['customer_update'])->toBe(['name' => 'auto', 'address' => 'auto'])
            ->and($params['client_reference_id'])->toBe((string) $workspace->id)
            ->and(implode("\n", $checkout['headers']))->toMatch('/Idempotency-Key: checkout-'.$workspace->id.'-pro-month-USD-cus_new_1-\d+/')
            ->and($workspace->refresh()->stripe_customer_id)->toBe('cus_new_1');

        $this->post(route('settings.billing.portal'), [], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://billing.stripe.com/p/session_1');

        $portal = collect($captured->getArrayCopy())->last();
        expect($portal['params']['customer'])->toBe('cus_new_1')
            ->and($portal['params']['configuration'])->toBe('bpc_ours');
    } finally {
        ApiRequestor::setHttpClient(null);
    }
});

it('requires a customer before opening the Stripe portal', function (): void {
    config(['billing.driver' => 'stripe']);
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);

    $this->post(route('settings.billing.portal'))->assertSessionHas('error', __('billing.errors.no_customer'));
    expect($workspace->refresh()->stripe_customer_id)->toBeNull();
});

it('refuses Stripe checkout for plans without a configured price or an unsupported currency', function (): void {
    config(['billing.driver' => 'stripe', 'billing.plans.pro.prices.USD' => null]);
    [$user, $workspace] = $this->member(WorkspaceRole::Owner);

    expect(fn () => app(StripeGateway::class)->createCheckoutSession($workspace, $user, 'pro', BillingInterval::Month, 'https://a', 'https://b'))
        ->toThrow(BillingException::class, 'price_not_configured');

    config(['billing.driver' => 'market']);
    $workspace->forceFill(['country' => 'RU', 'currency' => 'RUB'])->save();

    expect(fn () => app(StripeGateway::class)->createCheckoutSession($workspace, $user, 'starter', BillingInterval::Month, 'https://a', 'https://b'))
        ->toThrow(BillingException::class, 'currency_unsupported');
});
