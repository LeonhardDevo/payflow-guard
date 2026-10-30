<?php

declare(strict_types=1);

use App\Billing\BillingException;
use App\Billing\Payments\FakePaymentGateway;
use App\Billing\Payments\Money;
use App\Billing\Payments\PaymentAccounts;
use App\Billing\Payments\PaymentGateways;
use App\Billing\Payments\PaymentLinks;
use App\Billing\Payments\PaymentLinkStatusChanged;
use App\Billing\Payments\PaymentRequest;
use App\Billing\Payments\PaymentStatus;
use App\Billing\Payments\StripePaymentGateway;
use App\Billing\Payments\YooKassaPaymentGateway;
use App\Enums\WorkspaceRole;
use App\Models\PaymentAccount;
use App\Models\PaymentLinkRecord;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

function paymentRequest(Workspace $workspace, string $reference = 'invoice:42', int $amount = 12500, string $currency = 'USD'): PaymentRequest
{
    return new PaymentRequest(
        workspace: $workspace,
        reference: $reference,
        description: 'Invoice INV-42',
        money: Money::of($amount, $currency),
        returnUrl: 'https://app.test/pay/thanks',
        customerEmail: 'client@example.test',
    );
}

function stripeAccount(Workspace $workspace): PaymentAccount
{
    return PaymentAccount::query()->create([
        'workspace_id' => $workspace->id,
        'driver' => 'stripe',
        'credentials' => ['restricted_key' => 'rk_test_merchant', 'webhook_secret' => 'whsec_merchant'],
        'connected_at' => now(),
    ]);
}

it('creates, reconciles and deactivates links with the fake driver, idempotently by reference', function (): void {
    Event::fake([PaymentLinkStatusChanged::class]);
    Cache::flush();
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    $links = app(PaymentLinks::class);

    $link = $links->create(paymentRequest($workspace));
    expect($link->driver)->toBe('fake')
        ->and($link->status)->toBe(PaymentStatus::Pending)
        ->and($link->url)->toStartWith('https://app.test/pay/thanks?fake_payment=plink_fake_')
        ->and($links->create(paymentRequest($workspace))->id)->toBe($link->id);

    FakePaymentGateway::settle($link->provider_id, PaymentStatus::Succeeded);
    $this->internal('POST', '/internal/v1/payments/reconcile', [])->assertOk()->assertJson(['checked' => 1, 'changed' => 1]);

    expect($link->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($link->paid_at)->not->toBeNull();
    Event::assertDispatched(PaymentLinkStatusChanged::class, fn (PaymentLinkStatusChanged $event): bool => $event->link->is($link)
        && $event->previous === PaymentStatus::Pending);

    $this->internal('POST', '/internal/v1/payments/reconcile', [])->assertOk()->assertJson(['checked' => 0]);

    $other = $links->create(paymentRequest($workspace, 'invoice:43'));
    expect($links->deactivate($other)->status)->toBe(PaymentStatus::Canceled)
        ->and(fn () => $links->deactivate($link->refresh()))->toThrow(BillingException::class, 'not_supported');
});

it('refuses the fake driver outside fake billing and requires a connected account', function (): void {
    config(['billing.driver' => 'market']);
    [, $workspace] = $this->member(WorkspaceRole::Owner);

    expect(fn () => app(PaymentLinks::class)->create(paymentRequest($workspace)))
        ->toThrow(BillingException::class, 'payment_account_missing');

    $account = stripeAccount($workspace);
    expect(app(PaymentGateways::class)->forAccount($account))->toBeInstanceOf(StripePaymentGateway::class)
        ->and(app(PaymentGateways::class)->accountFor($workspace, 'RUB'))->toBeNull();
});

it('stores merchant credentials encrypted and hides them', function (): void {
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    $account = stripeAccount($workspace);

    $raw = (string) DB::table('payment_accounts')->where('id', $account->id)->value('credentials');
    expect($raw)->not->toContain('rk_test_merchant')
        ->and($account->refresh()->credential('restricted_key'))->toBe('rk_test_merchant')
        ->and($account->toArray())->not->toHaveKey('credentials');
});

it('serves the per-account webhook secret to the worker only', function (): void {
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    $account = stripeAccount($workspace);

    $this->internal('GET', '/internal/v1/payments/accounts/'.$account->id.'/webhook-secret')
        ->assertOk()->assertJson(['secret' => 'whsec_merchant']);
    $this->internal('GET', '/internal/v1/payments/accounts/'.$account->id.'/webhook-secret', service: 'ai')->assertForbidden();
    $this->get('/internal/v1/payments/accounts/'.$account->id.'/webhook-secret')->assertUnauthorized();
});

it('applies verified per-account Stripe events: paid, refunded, deduplicated', function (): void {
    config(['billing.driver' => 'market']);
    Event::fake([PaymentLinkStatusChanged::class]);
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    $account = stripeAccount($workspace);
    $record = PaymentLinkRecord::query()->create([
        'workspace_id' => $workspace->id,
        'payment_account_id' => $account->id,
        'driver' => 'stripe',
        'reference' => 'invoice:42',
        'provider_id' => 'plink_123',
        'url' => 'https://buy.stripe.com/test_123',
        'amount' => 12500,
        'currency' => 'USD',
        'status' => PaymentStatus::Pending,
    ]);
    $event = fn (string $id, string $type, array $object, int $created): array => [
        'id' => $id, 'object' => 'event', 'type' => $type, 'created' => $created, 'data' => ['object' => $object],
    ];

    $completed = $event('evt_1', 'checkout.session.completed', [
        'object' => 'checkout.session', 'payment_link' => 'plink_123', 'payment_status' => 'paid', 'payment_intent' => 'pi_123',
    ], time());
    $this->internal('POST', '/internal/v1/payments/stripe/events', ['account_id' => $account->id, 'event' => $completed])
        ->assertOk()->assertJson(['status' => 'processed']);
    $this->internal('POST', '/internal/v1/payments/stripe/events', ['account_id' => $account->id, 'event' => $completed])
        ->assertOk()->assertJson(['status' => 'duplicate']);

    expect($record->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($record->provider_payment_id)->toBe('pi_123');

    $this->internal('POST', '/internal/v1/payments/stripe/events', [
        'account_id' => $account->id,
        'event' => $event('evt_2', 'charge.refunded', ['object' => 'charge', 'payment_intent' => 'pi_123', 'refunded' => true], time() + 5),
    ])->assertOk()->assertJson(['status' => 'processed']);
    expect($record->refresh()->status)->toBe(PaymentStatus::Refunded);

    [, $foreign] = $this->member(WorkspaceRole::Owner);
    $foreignAccount = stripeAccount($foreign);
    $this->internal('POST', '/internal/v1/payments/stripe/events', [
        'account_id' => $foreignAccount->id,
        'event' => $event('evt_3', 'checkout.session.completed', ['payment_link' => 'plink_123', 'payment_status' => 'paid'], time()),
    ])->assertOk()->assertJson(['status' => 'ignored']);

    Event::assertDispatchedTimes(PaymentLinkStatusChanged::class, 2);
});

it('creates YooKassa client payments with the merchant shop and trusts only the re-fetched status', function (): void {
    config(['billing.driver' => 'market']);
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    Http::fake([
        'https://api.yookassa.test/v3/me' => Http::response(['account_id' => '777001', 'status' => 'enabled', 'test' => true]),
        'https://api.yookassa.test/v3/payments' => Http::response([
            'id' => '2f1a8000-000f-5000-9000-1b2c3d4e5f99',
            'status' => 'pending',
            'paid' => false,
            'amount' => ['value' => '5000.00', 'currency' => 'RUB'],
            'confirmation' => ['type' => 'redirect', 'confirmation_url' => 'https://yoomoney.ru/checkout/payments/v2/contract?orderId=2f1a8000'],
        ]),
        'https://api.yookassa.test/v3/payments/2f1a8000-000f-5000-9000-1b2c3d4e5f99' => Http::response([
            'id' => '2f1a8000-000f-5000-9000-1b2c3d4e5f99',
            'status' => 'succeeded',
            'paid' => true,
            'amount' => ['value' => '5000.00', 'currency' => 'RUB'],
        ]),
    ]);
    config(['billing.yookassa.api_base' => 'https://api.yookassa.test/v3']);

    expect(fn () => app(PaymentAccounts::class)->connectYooKassa($workspace, 'shop', 'test_x', true))
        ->toThrow(BillingException::class, 'payment_account_invalid');
    app(PaymentAccounts::class)->connectYooKassa($workspace, '777001', 'test_merchant_secret', true);

    $link = app(PaymentLinks::class)->create(paymentRequest($workspace, 'invoice:7', 500000, 'RUB'));
    expect($link->driver)->toBe('yookassa')
        ->and($link->url)->toStartWith('https://yoomoney.ru/');

    $create = collect(Http::recorded())->first(fn (array $pair): bool => $pair[0]->method() === 'POST')[0];
    expect($create->header('Authorization')[0])->toBe('Basic '.base64_encode('777001:test_merchant_secret'))
        ->and($create->data()['metadata'])->toMatchArray(['kind' => YooKassaPaymentGateway::KIND, 'reference' => 'invoice:7'])
        ->and($create->data()['receipt']['customer'])->toBe(['email' => 'client@example.test']);

    $this->internal('POST', '/internal/v1/yookassa/notifications', [
        'type' => 'notification',
        'event' => 'payment.succeeded',
        'object' => ['id' => '2f1a8000-000f-5000-9000-1b2c3d4e5f99', 'status' => 'succeeded'],
    ])->assertOk()->assertJson(['status' => 'client_payment', 'payment_status' => 'succeeded']);

    expect($link->refresh()->status)->toBe(PaymentStatus::Succeeded);
});

it('maps YooKassa payment states including full refunds', function (): void {
    expect(YooKassaPaymentGateway::map(['status' => 'pending']))->toBe(PaymentStatus::Pending)
        ->and(YooKassaPaymentGateway::map(['status' => 'waiting_for_capture']))->toBe(PaymentStatus::Pending)
        ->and(YooKassaPaymentGateway::map(['status' => 'canceled']))->toBe(PaymentStatus::Canceled)
        ->and(YooKassaPaymentGateway::map(['status' => 'succeeded', 'amount' => ['value' => '10.00'], 'refunded_amount' => ['value' => '10.00']]))->toBe(PaymentStatus::Refunded)
        ->and(YooKassaPaymentGateway::map(['status' => 'succeeded', 'amount' => ['value' => '10.00'], 'refunded_amount' => ['value' => '2.00']]))->toBe(PaymentStatus::Succeeded);
});

it('connects a merchant Stripe account and registers its own webhook endpoint', function (): void {
    config(['billing.payments.webhook_base_url' => 'https://worker.example.test/']);
    $captured = new ArrayObject;
    ApiRequestor::setHttpClient(new class($captured) implements ClientInterface
    {
        public function __construct(private ArrayObject $captured) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $this->captured->append(['path' => parse_url($absUrl, PHP_URL_PATH), 'params' => $params, 'headers' => $headers]);

            return [json_encode(['id' => 'we_123', 'object' => 'webhook_endpoint', 'secret' => 'whsec_registered']), 200, []];
        }
    });

    try {
        [, $workspace] = $this->member(WorkspaceRole::Owner);

        expect(fn () => app(PaymentAccounts::class)->connectStripe($workspace, 'pk_test_wrong'))
            ->toThrow(BillingException::class, 'payment_account_invalid');

        $account = app(PaymentAccounts::class)->connectStripe($workspace, 'rk_test_merchant');

        $call = $captured[0];
        expect($call['path'])->toBe('/v1/webhook_endpoints')
            ->and($call['params']['url'])->toBe('https://worker.example.test/webhooks/payments/stripe/'.$account->id)
            ->and($call['params']['enabled_events'])->toBe(StripePaymentGateway::WEBHOOK_EVENTS)
            ->and(implode("\n", $call['headers']))->toContain('Authorization: Bearer rk_test_merchant')
            ->and($account->webhook_endpoint_id)->toBe('we_123')
            ->and($account->credential('webhook_secret'))->toBe('whsec_registered')
            ->and($account->connected_at)->not->toBeNull();
    } finally {
        ApiRequestor::setHttpClient(null);
    }
});
