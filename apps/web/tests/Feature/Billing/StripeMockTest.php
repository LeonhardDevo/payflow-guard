<?php

declare(strict_types=1);

use App\Billing\Payments\Money;
use App\Billing\Payments\PaymentLinks;
use App\Billing\Payments\PaymentRequest;
use App\Billing\Payments\PaymentStatus;
use App\Billing\Stripe\StripeCatalogSync;
use App\Billing\StripeGateway;
use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceRole;
use App\Models\PaymentAccount;
use App\Models\Subscription;
use Illuminate\Support\Facades\Cache;
use Stripe\StripeClient;

function stripeMockBase(): string
{
    $base = getenv('STRIPE_MOCK_URL');

    return is_string($base) && $base !== '' ? rtrim($base, '/') : 'http://127.0.0.1:12111';
}

beforeEach(function (): void {
    $parts = parse_url(stripeMockBase());
    $socket = @fsockopen((string) ($parts['host'] ?? '127.0.0.1'), (int) ($parts['port'] ?? 12111), $errno, $error, 0.5);

    if ($socket === false) {
        $this->markTestSkipped('stripe-mock is not running (docker compose up -d stripe-mock).');
    }

    fclose($socket);

    config([
        'billing.driver' => 'stripe',
        'billing.stripe.secret' => 'sk_test_123',
        'billing.stripe.api_base' => stripeMockBase(),
        'billing.stripe.catalog' => 'mockcat',
        'billing.stripe.max_network_retries' => 0,
        'billing.stripe.automatic_tax' => true,
        'billing.trial_days' => 7,
    ]);
    app()->forgetInstance(StripeClient::class);
    Cache::flush();
});

it('creates a real Checkout Session and Portal session through the official SDK', function (): void {
    [$user, $workspace] = $this->member(WorkspaceRole::Owner);
    $gateway = app(StripeGateway::class);

    $url = $gateway->createCheckoutSession($workspace, $user, 'pro', BillingInterval::Month, 'https://app.test/ok', 'https://app.test/cancel');

    expect($url)->toStartWith('https://')
        ->and($workspace->refresh()->stripe_customer_id)->toStartWith('cus_');

    expect($gateway->createPortalSession($workspace, 'https://app.test/billing'))->toStartWith('https://');
})->group('stripe-mock');

it('changes the plan, cancels and resumes a subscription through the SDK', function (): void {
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    $workspace->forceFill(['country' => 'DE', 'currency' => 'EUR'])->save();
    $subscription = Subscription::query()->create([
        'workspace_id' => $workspace->id,
        'plan' => 'starter',
        'status' => SubscriptionStatus::Active,
        'interval' => 'month',
        'currency' => 'EUR',
        'stripe_customer_id' => 'cus_mock',
        'stripe_subscription_id' => 'sub_mock',
    ]);
    $gateway = app(StripeGateway::class);

    $gateway->changePlan($workspace, $subscription, 'pro', BillingInterval::Year);
    $gateway->cancelAtPeriodEnd($workspace, $subscription);
    $gateway->resume($workspace, $subscription);

    expect($subscription->refresh()->plan)->toBe('starter');
})->group('stripe-mock');

it('syncs products, prices by lookup_key and the portal configuration', function (): void {
    $report = app(StripeCatalogSync::class)->run();
    $kinds = array_count_values(array_column($report, 'kind'));
    $paidPlans = count(array_filter(array_keys((array) config('billing.plans')), fn (string $plan): bool => $plan !== 'free'));

    expect($kinds['product'])->toBe($paidPlans)
        ->and($kinds['price'])->toBe($paidPlans * 2 * 2)
        ->and($kinds['portal'])->toBe(1)
        ->and(array_column($report, 'key'))->toContain('mockcat_pro_year_eur')
        ->and(collect($report)->every(fn (array $row): bool => $row['id'] !== null))->toBeTrue();

    $this->artisan('billing:sync-stripe', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();
})->group('stripe-mock');

it('refuses to sync without a secret key', function (): void {
    config(['billing.stripe.secret' => null]);

    $this->artisan('billing:sync-stripe')->expectsOutputToContain('STRIPE_SECRET')->assertFailed();
})->group('stripe-mock');

it('runs merchant Payment Links through the official SDK', function (): void {
    config(['billing.driver' => 'market', 'billing.payments.webhook_base_url' => 'https://worker.example.test']);
    [, $workspace] = $this->member(WorkspaceRole::Owner);

    PaymentAccount::query()->create([
        'workspace_id' => $workspace->id,
        'driver' => 'stripe',
        'credentials' => ['restricted_key' => 'rk_test_merchant123', 'webhook_secret' => 'whsec_merchant'],
        'connected_at' => now(),
    ]);

    $links = app(PaymentLinks::class);
    $record = $links->create(new PaymentRequest(
        workspace: $workspace,
        reference: 'invoice:mock-1',
        description: 'Invoice MOCK-1',
        money: Money::of(9900, 'USD'),
        returnUrl: 'https://app.test/thanks',
    ));

    expect($record->driver)->toBe('stripe')
        ->and($record->provider_id)->toStartWith('plink_')
        ->and($record->url)->toStartWith('https://');

    $links->refresh($record);
    expect($record->refresh()->last_checked_at)->not->toBeNull()
        ->and($links->deactivate($record)->status)->toBe(PaymentStatus::Canceled);
});
