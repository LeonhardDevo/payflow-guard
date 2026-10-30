<?php

declare(strict_types=1);

namespace App\Billing;

use App\Billing\Markets\MarketResolver;
use App\Billing\Stripe\StripeCatalog;
use App\Enums\BillingInterval;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

final readonly class StripeGateway implements BillingGateway, ManagesSubscriptions
{
    public function __construct(
        private StripeClient $stripe,
        private StripeCatalog $catalog,
        private MarketResolver $markets,
    ) {}

    public function driver(): string
    {
        return 'stripe';
    }

    public function createCheckoutSession(
        Workspace $workspace,
        User $user,
        string $plan,
        BillingInterval $interval,
        string $successUrl,
        string $cancelUrl,
    ): string {
        $currency = $this->currencyFor($workspace);
        $price = $this->catalog->priceId($plan, $interval, $currency);

        if ($price === null) {
            throw new BillingException('price_not_configured');
        }

        $customerId = $this->ensureCustomer($workspace, $user);
        $metadata = $this->metadata($workspace, $plan, $interval, $currency);
        $subscriptionData = ['metadata' => $metadata];
        $trialDays = $this->trialDays($workspace);

        if ($trialDays > 0) {
            $subscriptionData['trial_period_days'] = $trialDays;
        }

        $params = [
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [['price' => $price, 'quantity' => 1]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $workspace->id,
            'metadata' => $metadata,
            'subscription_data' => $subscriptionData,
            'allow_promotion_codes' => true,
            'billing_address_collection' => 'auto',
            'locale' => self::locale(),
        ];

        $customerUpdate = [];

        if ((bool) config('billing.stripe.tax_id_collection', true)) {
            $params['tax_id_collection'] = ['enabled' => true];
            $customerUpdate['name'] = 'auto';
        }

        if ((bool) config('billing.stripe.automatic_tax', false)) {
            $params['automatic_tax'] = ['enabled' => true];
            $customerUpdate['address'] = 'auto';
        }

        if ($customerUpdate !== []) {
            $params['customer_update'] = $customerUpdate;
        }

        $session = $this->call('checkout', $workspace, fn () => $this->stripe->checkout->sessions->create(
            $params,
            ['idempotency_key' => self::key('checkout', (string) $workspace->id, $plan, $interval->value, $currency, $customerId, (string) intdiv(time(), 600))],
        ));

        if (! is_string($session->url) || $session->url === '') {
            throw new BillingException('provider_unavailable');
        }

        return $session->url;
    }

    public function createPortalSession(Workspace $workspace, string $returnUrl): string
    {
        if ($workspace->stripe_customer_id === null) {
            throw new BillingException('no_customer');
        }

        $params = [
            'customer' => $workspace->stripe_customer_id,
            'return_url' => $returnUrl,
            'locale' => self::locale(),
        ];

        $configuration = $this->catalog->portalConfigurationId();

        if ($configuration !== null) {
            $params['configuration'] = $configuration;
        }

        $session = $this->call('portal', $workspace, fn () => $this->stripe->billingPortal->sessions->create(
            $params,
            ['idempotency_key' => self::key('portal', (string) $workspace->id, (string) intdiv(time(), 60))],
        ));

        return (string) $session->url;
    }

    public function changePlan(Workspace $workspace, Subscription $subscription, string $plan, BillingInterval $interval): void
    {
        $subscriptionId = $this->subscriptionId($subscription);
        $currency = $this->currencyFor($workspace, $subscription);
        $price = $this->catalog->priceId($plan, $interval, $currency);

        if ($price === null) {
            throw new BillingException('price_not_configured');
        }

        $remote = $this->call('retrieve', $workspace, fn () => $this->stripe->subscriptions->retrieve($subscriptionId));
        $itemId = $remote->items->data[0]->id ?? null;

        if (! is_string($itemId)) {
            throw new BillingException('provider_unavailable');
        }

        $this->call('change_plan', $workspace, fn () => $this->stripe->subscriptions->update($subscriptionId, [
            'items' => [['id' => $itemId, 'price' => $price]],
            'proration_behavior' => 'create_prorations',
            'cancel_at_period_end' => false,
            'metadata' => $this->metadata($workspace, $plan, $interval, $currency),
        ], ['idempotency_key' => self::key('change', $subscriptionId, $plan, $interval->value, (string) $subscription->last_event_at, (string) intdiv(time(), 60))]));
    }

    public function cancelAtPeriodEnd(Workspace $workspace, Subscription $subscription): void
    {
        $this->setCancelAtPeriodEnd($workspace, $subscription, true);
    }

    public function resume(Workspace $workspace, Subscription $subscription): void
    {
        $this->setCancelAtPeriodEnd($workspace, $subscription, false);
    }

    private function setCancelAtPeriodEnd(Workspace $workspace, Subscription $subscription, bool $cancel): void
    {
        $subscriptionId = $this->subscriptionId($subscription);

        $this->call($cancel ? 'cancel' : 'resume', $workspace, fn () => $this->stripe->subscriptions->update(
            $subscriptionId,
            ['cancel_at_period_end' => $cancel],
            ['idempotency_key' => self::key($cancel ? 'cancel' : 'resume', $subscriptionId, (string) $subscription->last_event_at, (string) intdiv(time(), 60))],
        ));
    }

    private function ensureCustomer(Workspace $workspace, User $user): string
    {
        if ($workspace->stripe_customer_id !== null) {
            return $workspace->stripe_customer_id;
        }

        $customer = $this->call('customer', $workspace, fn () => $this->stripe->customers->create([
            'email' => $user->email,
            'name' => $workspace->name,
            'preferred_locales' => [App::getLocale()],
            'metadata' => [
                'workspace_id' => (string) $workspace->id,
                'catalog' => PlanCatalog::catalogName(),
            ],
        ], ['idempotency_key' => 'customer-'.PlanCatalog::catalogName().'-workspace-'.$workspace->id]));

        $workspace->forceFill(['stripe_customer_id' => $customer->id])->save();

        return (string) $customer->id;
    }

    private function trialDays(Workspace $workspace): int
    {
        $days = (int) config('billing.trial_days', 0);

        if ($days <= 0) {
            return 0;
        }

        $hadSubscription = Subscription::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('stripe_subscription_id')
            ->exists();

        return $hadSubscription ? 0 : $days;
    }

    private function currencyFor(Workspace $workspace, ?Subscription $subscription = null): string
    {
        $supported = $this->markets->currenciesFor('stripe');
        $currency = strtoupper((string) ($subscription->currency ?? $this->markets->forWorkspace($workspace)->currency));

        if (! in_array($currency, $supported, true)) {
            throw new BillingException('currency_unsupported');
        }

        return $currency;
    }

    private function subscriptionId(Subscription $subscription): string
    {
        if ($subscription->stripe_subscription_id === null) {
            throw new BillingException('no_subscription');
        }

        return $subscription->stripe_subscription_id;
    }

    /** @return array<string, string> */
    private function metadata(Workspace $workspace, string $plan, BillingInterval $interval, string $currency): array
    {
        return [
            'workspace_id' => (string) $workspace->id,
            'plan' => $plan,
            'interval' => $interval->value,
            'currency' => $currency,
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $request
     * @return T
     */
    private function call(string $operation, Workspace $workspace, callable $request): mixed
    {
        try {
            return $request();
        } catch (ApiErrorException $exception) {
            Log::error('billing.stripe.'.$operation.'_failed', [
                'workspace_id' => $workspace->id,
                'status' => $exception->getHttpStatus(),
                'code' => $exception->getStripeCode(),
                'error' => $exception->getMessage(),
            ]);

            throw new BillingException('provider_unavailable', $exception);
        }
    }

    private static function key(string ...$parts): string
    {
        return implode('-', $parts);
    }

    private static function locale(): string
    {
        return in_array(App::getLocale(), ['en', 'ru'], true) ? App::getLocale() : 'auto';
    }
}
