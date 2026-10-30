<?php

declare(strict_types=1);

namespace App\Billing\YooKassa;

use App\Billing\BillingException;
use App\Billing\BillingGateway;
use App\Billing\ManagesSubscriptions;
use App\Billing\Markets\MarketResolver;
use App\Billing\PlanCatalog;
use App\Enums\BillingInterval;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

final readonly class YooKassaGateway implements BillingGateway, ManagesSubscriptions
{
    public const string KIND_INITIAL = 'subscription_initial';

    public const string KIND_RENEWAL = 'subscription_renewal';

    public function __construct(
        private YooKassaClient $client,
        private PlanCatalog $catalog,
        private MarketResolver $markets,
        private YooKassaSubscriptions $subscriptions,
    ) {}

    public function driver(): string
    {
        return 'yookassa';
    }

    public function createCheckoutSession(
        Workspace $workspace,
        User $user,
        string $plan,
        BillingInterval $interval,
        string $successUrl,
        string $cancelUrl,
    ): string {
        if ($workspace->subscription !== null && $workspace->subscription->grantsPlan() && $workspace->subscription->gateway === 'yookassa') {
            throw new BillingException('already_subscribed');
        }

        $currency = $this->currencyFor($workspace);
        $amount = $this->priceFor($plan, $interval, $currency);

        $payload = [
            'amount' => YooKassaClient::amount($amount, $currency),
            'capture' => true,
            'save_payment_method' => true,
            'confirmation' => [
                'type' => 'redirect',
                'return_url' => $successUrl,
                'locale' => App::getLocale() === 'ru' ? 'ru_RU' : 'en_US',
            ],
            'description' => $this->description($workspace, $plan, $interval),
            'metadata' => [
                'kind' => self::KIND_INITIAL,
                'workspace_id' => (string) $workspace->id,
                'plan' => $plan,
                'interval' => $interval->value,
                'currency' => $currency,
            ],
        ];

        $receipt = $this->receipt($user->email, $plan, $interval, $amount, $currency);

        if ($receipt !== null) {
            $payload['receipt'] = $receipt;
        }

        $payment = $this->client->createPayment(
            $payload,
            self::key('checkout', (string) $workspace->id, $plan, $interval->value, $currency, (string) intdiv(time(), 600)),
        );

        $confirmation = $payment['confirmation'] ?? null;
        $url = is_array($confirmation) ? ($confirmation['confirmation_url'] ?? null) : null;

        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new BillingException('provider_unavailable');
        }

        return $url;
    }

    public function createPortalSession(Workspace $workspace, string $returnUrl): ?string
    {
        throw new BillingException('not_supported');
    }

    public function changePlan(Workspace $workspace, Subscription $subscription, string $plan, BillingInterval $interval): void
    {
        $this->assertOwned($subscription);
        $this->priceFor($plan, $interval, $this->currencyFor($workspace, $subscription));

        $unchanged = $subscription->plan === $plan && $subscription->interval === $interval->value;

        $this->subscriptions->apply($workspace, [
            'scheduled_plan' => $unchanged ? null : $plan,
            'scheduled_interval' => $unchanged ? null : $interval->value,
            'cancel_at_period_end' => false,
        ], 'yookassa.change_plan', self::actor());
    }

    public function cancelAtPeriodEnd(Workspace $workspace, Subscription $subscription): void
    {
        $this->assertOwned($subscription);
        $this->subscriptions->apply($workspace, ['cancel_at_period_end' => true], 'yookassa.cancel', self::actor());
    }

    public function resume(Workspace $workspace, Subscription $subscription): void
    {
        $this->assertOwned($subscription);
        $this->subscriptions->apply($workspace, ['cancel_at_period_end' => false], 'yookassa.resume', self::actor());
    }

    public function priceFor(string $plan, BillingInterval $interval, string $currency): int
    {
        if (! $this->catalog->hasPrice($plan, $interval, $currency)) {
            throw new BillingException('price_not_configured');
        }

        $amount = $this->catalog->amount($plan, $interval, $currency);

        if ($amount <= 0) {
            throw new BillingException('price_not_configured');
        }

        return $amount;
    }

    /** @return array<string, mixed>|null */
    public function receipt(?string $email, string $plan, BillingInterval $interval, int $amount, string $currency): ?array
    {
        if (! (bool) config('billing.yookassa.receipts', false)) {
            return null;
        }

        if ($email === null || $email === '') {
            throw new BillingException('receipt_email_missing');
        }

        $receipt = [
            'customer' => ['email' => $email],
            'items' => [[
                'description' => Str::limit($this->itemName($plan, $interval), 128, ''),
                'quantity' => '1.00',
                'amount' => YooKassaClient::amount($amount, $currency),
                'vat_code' => (int) config('billing.yookassa.vat_code', 1),
                'payment_subject' => 'service',
                'payment_mode' => 'full_payment',
            ]],
        ];

        $taxSystem = config('billing.yookassa.tax_system_code');

        if (is_int($taxSystem)) {
            $receipt['tax_system_code'] = $taxSystem;
        }

        return $receipt;
    }

    public function description(Workspace $workspace, string $plan, BillingInterval $interval): string
    {
        return Str::limit($this->itemName($plan, $interval).' — '.$workspace->name, 128, '');
    }

    public static function key(string ...$parts): string
    {
        $key = implode('-', $parts);

        return strlen($key) <= YooKassaClient::MAX_IDEMPOTENCE_KEY_LENGTH ? $key : substr($parts[0], 0, 16).'-'.substr(hash('sha256', $key), 0, 40);
    }

    private function itemName(string $plan, BillingInterval $interval): string
    {
        return (string) __('billing.yookassa_item', [
            'product' => (string) config('app.name'),
            'plan' => (string) __("billing.plans.{$plan}.name"),
            'interval' => (string) __("billing.yookassa_intervals.{$interval->value}"),
        ]);
    }

    private function currencyFor(Workspace $workspace, ?Subscription $subscription = null): string
    {
        $currency = strtoupper((string) ($subscription->currency ?? $this->markets->forWorkspace($workspace)->currency));

        if (! in_array($currency, $this->markets->currenciesFor('yookassa'), true)) {
            throw new BillingException('currency_unsupported');
        }

        return $currency;
    }

    private function assertOwned(Subscription $subscription): void
    {
        if ($subscription->gateway !== 'yookassa' || ! $subscription->grantsPlan()) {
            throw new BillingException('no_subscription');
        }
    }

    private static function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
