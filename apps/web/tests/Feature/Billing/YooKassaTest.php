<?php

declare(strict_types=1);

use App\Billing\BillingGateways;
use App\Billing\Mail\PaymentFailedMail;
use App\Billing\YooKassa\YooKassaClient;
use App\Billing\YooKassa\YooKassaGateway;
use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceRole;
use App\Models\Subscription;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

const YOOKASSA_API = 'https://api.yookassa.test/v3';

/**
 * @param  array<string, string>  $replace
 * @return array<string, mixed>
 */
function yookassaFixture(string $name, array $replace = []): array
{
    $json = str_replace(array_keys($replace), array_values($replace), (string) file_get_contents(base_path('../../contracts/yookassa/'.$name.'.json')));

    return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
}

function yookassaSubscription(Workspace $workspace, array $attributes = []): Subscription
{
    return Subscription::query()->create([
        'workspace_id' => $workspace->id,
        'gateway' => 'yookassa',
        'plan' => 'starter',
        'status' => SubscriptionStatus::Active,
        'interval' => 'month',
        'currency' => 'RUB',
        'provider_payment_method_id' => '2f1a7c3e-000f-5000-9000-1b2c3d4e5f60',
        'current_period_end' => now()->subHour(),
        ...$attributes,
    ]);
}

beforeEach(function (): void {
    config([
        'billing.driver' => 'market',
        'billing.yookassa.shop_id' => '100500',
        'billing.yookassa.secret_key' => 'test_secret_key',
        'billing.yookassa.api_base' => YOOKASSA_API,
        'billing.yookassa.receipts' => true,
    ]);
    Mail::fake();
});

it('is unavailable until the shop credentials are configured', function (): void {
    config(['billing.yookassa.shop_id' => null]);
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $workspace->forceFill(['country' => 'RU', 'currency' => 'RUB'])->save();

    expect(app(BillingGateways::class)->available('yookassa'))->toBeFalse();
    $this->get(route('settings.billing'))->assertInertia(fn ($page) => $page->where('market.available', false));
    $this->post(route('settings.billing.checkout'), ['plan' => 'starter', 'interval' => 'month'])
        ->assertSessionHas('error', __('billing.errors.gateway_unavailable'));

    config(['billing.yookassa.shop_id' => '100500']);
    expect(app(BillingGateways::class)->forWorkspace($workspace))->toBeInstanceOf(YooKassaGateway::class);
});

it('creates a redirect payment that saves the card, with a 54-FZ receipt and a stable Idempotence-Key', function (): void {
    [$user, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $workspace->forceFill(['country' => 'RU', 'currency' => 'RUB'])->save();
    Http::fake([YOOKASSA_API.'/payments' => Http::response(yookassaFixture('payments/initial_pending', [
        '__WORKSPACE_ID__' => (string) $workspace->id,
        '__RETURN_URL__' => route('settings.billing', ['checkout' => 'success']),
    ]))]);

    $first = $this->post(route('settings.billing.checkout'), ['plan' => 'starter', 'interval' => 'month']);
    $first->assertRedirect('https://yoomoney.ru/checkout/payments/v2/contract?orderId=2f1a7c3e-000f-5000-9000-1b2c3d4e5f60');
    $this->post(route('settings.billing.checkout'), ['plan' => 'starter', 'interval' => 'month']);

    $requests = array_map(fn (array $pair): HttpRequest => $pair[0], Http::recorded()->all());
    expect($requests)->toHaveCount(2);

    [$request, $retry] = $requests;
    $body = $request->data();
    expect($request->method())->toBe('POST')
        ->and($request->header('Authorization')[0])->toBe('Basic '.base64_encode('100500:test_secret_key'))
        ->and($request->header('Idempotence-Key')[0])->toStartWith('checkout-'.$workspace->id.'-starter-month-RUB-')
        ->and($retry->header('Idempotence-Key')[0])->toBe($request->header('Idempotence-Key')[0])
        ->and($body['amount'])->toBe(['value' => '1490.00', 'currency' => 'RUB'])
        ->and($body['capture'])->toBeTrue()
        ->and($body['save_payment_method'])->toBeTrue()
        ->and($body['confirmation']['type'])->toBe('redirect')
        ->and($body['metadata'])->toMatchArray(['kind' => 'subscription_initial', 'workspace_id' => (string) $workspace->id, 'plan' => 'starter'])
        ->and($body['receipt']['customer'])->toBe(['email' => $user->email])
        ->and($body['receipt']['items'][0])->toMatchArray([
            'quantity' => '1.00',
            'amount' => ['value' => '1490.00', 'currency' => 'RUB'],
            'vat_code' => 1,
            'payment_subject' => 'service',
            'payment_mode' => 'full_payment',
        ]);
});

it('activates the subscription only from the re-fetched payment, idempotently by payment id', function (): void {
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    $workspace->forceFill(['country' => 'RU', 'currency' => 'RUB'])->save();
    $paymentId = '2f1a7c3e-000f-5000-9000-1b2c3d4e5f60';
    $notification = yookassaFixture('notifications/payment.succeeded');

    $succeeded = yookassaFixture('payments/initial_succeeded', ['__WORKSPACE_ID__' => (string) $workspace->id]);
    Http::fake([YOOKASSA_API.'/payments/'.$paymentId => Http::sequence()
        ->push(yookassaFixture('payments/initial_pending', [
            '__WORKSPACE_ID__' => (string) $workspace->id,
            '__RETURN_URL__' => 'https://app.test/settings/billing',
        ]))
        ->push($succeeded)
        ->push($succeeded)]);
    $this->internal('POST', '/internal/v1/yookassa/notifications', $notification)->assertOk()->assertJson(['status' => 'pending']);
    expect(Subscription::query()->count())->toBe(0);

    $this->internal('POST', '/internal/v1/yookassa/notifications', $notification)->assertOk()->assertJson(['status' => 'processed']);
    $this->internal('POST', '/internal/v1/yookassa/notifications', $notification)->assertOk()->assertJson(['status' => 'duplicate']);

    $subscription = Subscription::query()->sole();
    expect($subscription->gateway)->toBe('yookassa')
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->plan)->toBe('starter')
        ->and($subscription->currency)->toBe('RUB')
        ->and($subscription->provider_payment_method_id)->toBe($paymentId)
        ->and($subscription->current_period_end?->toIso8601String())->toBe('2026-11-23T09:01:12+00:00')
        ->and($workspace->refresh()->planKey())->toBe('starter');

    $this->internal('POST', '/internal/v1/yookassa/notifications', ['event' => 'payment.succeeded', 'object' => ['id' => '../payments']])
        ->assertUnprocessable();
});

it('ignores an underpaid or foreign payment even when it succeeded', function (): void {
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    $payment = yookassaFixture('payments/initial_succeeded', ['__WORKSPACE_ID__' => (string) $workspace->id]);
    $payment['amount']['value'] = '1.00';
    Http::fake([YOOKASSA_API.'/payments/*' => Http::response($payment)]);

    $this->internal('POST', '/internal/v1/yookassa/notifications', yookassaFixture('notifications/payment.succeeded'))
        ->assertOk()->assertJson(['status' => 'ignored']);
    expect(Subscription::query()->count())->toBe(0);
});

it('charges the saved payment method at renewal and extends the period', function (): void {
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    $subscription = yookassaSubscription($workspace, ['scheduled_plan' => 'pro']);
    $periodEnd = (string) $subscription->current_period_end?->getTimestamp();
    $renewal = yookassaFixture('payments/renewal_succeeded', [
        '__WORKSPACE_ID__' => (string) $workspace->id,
        '__PERIOD_END__' => $periodEnd,
        '__CAPTURED_AT__' => now()->toIso8601String(),
    ]);
    $renewal['metadata']['plan'] = 'pro';
    $renewal['amount']['value'] = '3990.00';
    Http::fake([YOOKASSA_API.'/payments' => Http::response($renewal)]);

    $this->internal('POST', '/internal/v1/billing/renewals', [])->assertOk()->assertJson(['due' => 1, 'succeeded' => 1]);

    $request = Http::recorded()[0][0];
    expect($request->data()['payment_method_id'])->toBe('2f1a7c3e-000f-5000-9000-1b2c3d4e5f60')
        ->and($request->data()['amount'])->toBe(['value' => '3990.00', 'currency' => 'RUB'])
        ->and($request->data()['metadata'])->toMatchArray(['kind' => 'subscription_renewal', 'plan' => 'pro', 'period_end' => $periodEnd])
        ->and($request->header('Idempotence-Key')[0])->toStartWith('renewal-'.$subscription->id.'-'.$periodEnd.'-');

    $subscription->refresh();
    expect($subscription->plan)->toBe('pro')
        ->and($subscription->scheduled_plan)->toBeNull()
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->current_period_end?->getTimestamp())
        ->toBe(CarbonImmutable::createFromTimestampUTC((int) $periodEnd)->addMonthNoOverflow()->getTimestamp());

    $this->internal('POST', '/internal/v1/billing/renewals', [])->assertOk()->assertJson(['due' => 0]);
});

it('moves a failed renewal to past_due with one e-mail, retries later and cancels after the grace period', function (): void {
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    $subscription = yookassaSubscription($workspace);
    $periodEnd = (string) $subscription->current_period_end?->getTimestamp();
    Http::fake([YOOKASSA_API.'/payments' => Http::response(yookassaFixture('payments/renewal_canceled', [
        '__WORKSPACE_ID__' => (string) $workspace->id,
        '__PERIOD_END__' => $periodEnd,
        '__CAPTURED_AT__' => now()->toIso8601String(),
    ]))]);

    $this->internal('POST', '/internal/v1/billing/renewals', [])->assertOk()->assertJson(['failed' => 1]);
    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->payment_failed_at)->not->toBeNull();
    Mail::assertSent(PaymentFailedMail::class, 1);

    $this->internal('POST', '/internal/v1/billing/renewals', [])->assertOk()->assertJson(['skipped' => 1]);
    expect(Http::recorded())->toHaveCount(1);

    $this->travel(8)->days();
    $this->internal('POST', '/internal/v1/billing/renewals', [])->assertOk()->assertJson(['canceled' => 1]);
    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Canceled)
        ->and($workspace->refresh()->planKey())->toBe('free');
    Mail::assertSent(PaymentFailedMail::class, 1);
});

it('ends a subscription canceled at period end without charging', function (): void {
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    $subscription = yookassaSubscription($workspace, ['cancel_at_period_end' => true]);
    Http::fake();

    $this->internal('POST', '/internal/v1/billing/renewals', [])->assertOk()->assertJson(['canceled' => 1]);

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Canceled);
    Http::assertNothingSent();
});

it('manages the YooKassa subscription locally: schedule a plan change, cancel and resume', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $workspace->forceFill(['country' => 'RU', 'currency' => 'RUB'])->save();
    yookassaSubscription($workspace, ['current_period_end' => now()->addDays(10)]);
    Http::fake();

    $this->post(route('settings.billing.change-plan'), ['plan' => 'pro', 'interval' => 'month'])
        ->assertSessionHas('success', __('billing.plan_change_scheduled'));
    $this->post(route('settings.billing.cancel'))->assertSessionHas('success');
    expect(Subscription::query()->sole()->only(['plan', 'scheduled_plan', 'cancel_at_period_end']))
        ->toBe(['plan' => 'starter', 'scheduled_plan' => 'pro', 'cancel_at_period_end' => true]);

    $this->post(route('settings.billing.resume'))->assertSessionHas('success');
    expect(Subscription::query()->sole()->cancel_at_period_end)->toBeFalse();

    $this->post(route('settings.billing.checkout'), ['plan' => 'pro', 'interval' => 'month'])
        ->assertSessionHas('error', __('billing.errors.already_subscribed'));
    $this->post(route('settings.billing.portal'))->assertSessionHas('error', __('billing.errors.not_supported'));

    Http::assertNothingSent();
});

it('formats amounts in minor units both ways', function (): void {
    expect(YooKassaClient::amount(149000, 'rub'))->toBe(['value' => '1490.00', 'currency' => 'RUB'])
        ->and(YooKassaClient::amount(5, 'RUB'))->toBe(['value' => '0.05', 'currency' => 'RUB'])
        ->and(YooKassaClient::minorUnits(['value' => '1490.5']))->toBe(149050)
        ->and(YooKassaClient::minorUnits(['value' => 'abc']))->toBeNull()
        ->and(strlen(YooKassaGateway::key('renewal', str_repeat('9', 80))))->toBeLessThanOrEqual(64);
});
