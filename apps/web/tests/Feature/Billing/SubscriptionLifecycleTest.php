<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceRole;
use App\Models\Subscription;
use App\Models\Workspace;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;

function activeSubscription(Workspace $workspace, array $attributes = []): Subscription
{
    return Subscription::query()->create([
        'workspace_id' => $workspace->id,
        'plan' => 'starter',
        'status' => SubscriptionStatus::Active,
        'interval' => 'month',
        'currency' => 'USD',
        'stripe_customer_id' => 'cus_life',
        'stripe_subscription_id' => 'sub_life',
        'current_period_end' => now()->addDays(20),
        'last_event_at' => time() - 60,
        ...$attributes,
    ]);
}

function forwardRecordedStripeEvents(object $test): void
{
    foreach (Http::recorded() as [$request]) {
        /** @var HttpRequest $request */
        $test->internal('POST', '/internal/v1/stripe/events', json_decode($request->body(), true))->assertOk();
    }
}

it('changes the plan through signed subscription events (fake driver)', function (): void {
    Http::fake(['worker.test/webhooks/stripe' => Http::response(['received' => true])]);
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    activeSubscription($workspace);

    $this->post(route('settings.billing.change-plan'), ['plan' => 'pro', 'interval' => 'year'])
        ->assertRedirect(route('settings.billing', ['checkout' => 'updated']))
        ->assertSessionHas('success');

    $event = json_decode(Http::recorded()[0][0]->body(), true);
    expect($event['type'])->toBe('customer.subscription.updated')
        ->and($event['data']['object']['items']['data'][0]['price']['lookup_key'])->toEndWith('_pro_year_usd');

    forwardRecordedStripeEvents($this);

    $subscription = Subscription::query()->sole();
    expect($subscription->plan)->toBe('pro')
        ->and($subscription->interval)->toBe('year')
        ->and($workspace->refresh()->planKey())->toBe('pro');
});

it('cancels at period end and resumes (fake driver)', function (): void {
    Http::fake(['worker.test/webhooks/stripe' => Http::response(['received' => true])]);
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    activeSubscription($workspace);

    $this->post(route('settings.billing.cancel'))->assertSessionHas('success');
    forwardRecordedStripeEvents($this);
    expect(Subscription::query()->sole()->cancel_at_period_end)->toBeTrue()
        ->and($workspace->refresh()->planKey())->toBe('starter');

    Http::fake(['worker.test/webhooks/stripe' => Http::response(['received' => true])]);
    $this->travel(2)->seconds();
    $this->post(route('settings.billing.resume'))->assertSessionHas('success');
    forwardRecordedStripeEvents($this);
    expect(Subscription::query()->sole()->cancel_at_period_end)->toBeFalse();
});

it('refuses lifecycle actions without a live subscription or permission', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);

    $this->post(route('settings.billing.cancel'))->assertSessionHas('error', __('billing.errors.no_subscription'));

    activeSubscription($workspace);
    $this->post(route('settings.billing.change-plan'), ['plan' => 'starter', 'interval' => 'month'])
        ->assertSessionHas('error', __('billing.errors.nothing_to_change'));

    [, $other] = $this->signInMember(WorkspaceRole::Member);
    activeSubscription($other, ['stripe_subscription_id' => 'sub_other']);
    $this->post(route('settings.billing.cancel'))->assertForbidden();
    $this->post(route('settings.billing.change-plan'), ['plan' => 'pro', 'interval' => 'month'])->assertForbidden();
});

it('updates the Stripe subscription with proration and cancels at period end (stripe driver)', function (): void {
    config(['billing.driver' => 'stripe', 'billing.stripe.catalog' => 'testcat']);
    $captured = new ArrayObject;
    ApiRequestor::setHttpClient(new class($captured) implements ClientInterface
    {
        public function __construct(private ArrayObject $captured) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $this->captured->append(['method' => $method, 'path' => parse_url($absUrl, PHP_URL_PATH), 'params' => $params, 'headers' => $headers]);
            $path = (string) parse_url($absUrl, PHP_URL_PATH);

            $body = $path === '/v1/prices'
                ? ['object' => 'list', 'url' => $path, 'has_more' => false, 'data' => [['id' => 'price_business_y', 'object' => 'price']]]
                : ['id' => 'sub_life', 'object' => 'subscription', 'items' => ['object' => 'list', 'data' => [['id' => 'si_life', 'object' => 'subscription_item']]]];

            return [json_encode($body), 200, []];
        }
    });
    app()->forgetInstance(StripeClient::class);

    try {
        [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
        activeSubscription($workspace);

        $this->post(route('settings.billing.change-plan'), ['plan' => 'business', 'interval' => 'year'])->assertSessionHas('success');
        $this->post(route('settings.billing.cancel'))->assertSessionHas('success');

        $calls = $captured->getArrayCopy();
        expect(array_column($calls, 'path'))->toBe(['/v1/prices', '/v1/subscriptions/sub_life', '/v1/subscriptions/sub_life', '/v1/subscriptions/sub_life'])
            ->and($calls[0]['params']['lookup_keys'])->toBe(['testcat_business_year_usd'])
            ->and($calls[2]['method'])->toBe('post')
            ->and($calls[2]['params']['items'])->toBe([['id' => 'si_life', 'price' => 'price_business_y']])
            ->and($calls[2]['params']['proration_behavior'])->toBe('create_prorations')
            ->and($calls[2]['params']['metadata']['plan'])->toBe('business')
            ->and(implode("\n", $calls[2]['headers']))->toContain('Idempotency-Key: change-sub_life-business-year-')
            ->and($calls[3]['params'])->toBe(['cancel_at_period_end' => 'true']);

        expect(Subscription::query()->sole()->plan)->toBe('starter');
    } finally {
        ApiRequestor::setHttpClient(null);
    }
});

it('rejects a market change that would move a live subscription to another gateway', function (): void {
    config(['billing.driver' => 'market', 'billing.yookassa.shop_id' => '100500', 'billing.yookassa.secret_key' => 'test_secret']);
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $workspace->forceFill(['country' => 'US', 'currency' => 'USD'])->save();
    activeSubscription($workspace, ['gateway' => 'stripe']);

    $this->patch(route('settings.workspace.update'), [
        'name' => $workspace->name,
        'timezone' => $workspace->timezone,
        'country' => 'RU',
        'currency' => 'RUB',
    ])->assertSessionHasErrors(['country' => __('billing.errors.market_locked_by_subscription', ['gateway' => 'Stripe'])]);

    expect($workspace->refresh()->country)->toBe('US');

    $this->patch(route('settings.workspace.update'), [
        'name' => $workspace->name,
        'timezone' => $workspace->timezone,
        'country' => 'DE',
        'currency' => 'EUR',
    ])->assertSessionHasNoErrors();

    expect($workspace->refresh()->country)->toBe('DE');
});

it('manages a subscription through the gateway it was created on after the market changed (stripe driver)', function (): void {
    config(['billing.driver' => 'market', 'billing.stripe.catalog' => 'testcat']);
    $captured = new ArrayObject;
    ApiRequestor::setHttpClient(new class($captured) implements ClientInterface
    {
        public function __construct(private ArrayObject $captured) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $this->captured->append(['path' => parse_url($absUrl, PHP_URL_PATH), 'params' => $params, 'headers' => $headers]);

            return [json_encode(['id' => 'sub_life', 'object' => 'subscription']), 200, []];
        }
    });
    app()->forgetInstance(StripeClient::class);

    try {
        [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
        $subscription = activeSubscription($workspace, ['gateway' => 'stripe']);
        $workspace->forceFill(['country' => 'RU', 'currency' => 'RUB'])->save();

        $this->post(route('settings.billing.cancel'))->assertSessionHas('success');
        $this->post(route('settings.billing.cancel'))->assertSessionHas('success');

        $calls = $captured->getArrayCopy();
        expect(array_column($calls, 'path'))->toBe(['/v1/subscriptions/sub_life', '/v1/subscriptions/sub_life'])
            ->and($calls[0]['params'])->toBe(['cancel_at_period_end' => 'true']);

        $keys = array_map(
            fn (array $call): string => (string) collect($call['headers'])->first(fn (string $header): bool => str_starts_with($header, 'Idempotency-Key: ')),
            $calls,
        );
        expect($keys[0])->toStartWith('Idempotency-Key: cancel-sub_life-')
            ->and($keys[1])->toBe($keys[0]);

        $subscription->forceFill(['last_event_at' => $subscription->last_event_at + 5])->save();
        $this->post(route('settings.billing.cancel'))->assertSessionHas('success');
        $third = collect($captured[2]['headers'])->first(fn (string $header): bool => str_starts_with($header, 'Idempotency-Key: '));
        expect($third)->toStartWith('Idempotency-Key: cancel-sub_life-')->not->toBe($keys[0]);
    } finally {
        ApiRequestor::setHttpClient(null);
    }
});
