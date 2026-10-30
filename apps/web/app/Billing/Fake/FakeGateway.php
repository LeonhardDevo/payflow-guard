<?php

declare(strict_types=1);

namespace App\Billing\Fake;

use App\Billing\BillingException;
use App\Billing\BillingGateway;
use App\Billing\ManagesSubscriptions;
use App\Billing\Markets\MarketResolver;
use App\Billing\PlanCatalog;
use App\Enums\BillingInterval;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

final readonly class FakeGateway implements BillingGateway, ManagesSubscriptions
{
    public function __construct(
        private FakeCheckoutStore $store,
        private PlanCatalog $catalog,
        private MarketResolver $markets,
        private FakeStripeEvents $events,
        private FakeWebhookDispatcher $dispatcher,
    ) {}

    public function driver(): string
    {
        return 'fake';
    }

    public function createCheckoutSession(
        Workspace $workspace,
        User $user,
        string $plan,
        BillingInterval $interval,
        string $successUrl,
        string $cancelUrl,
    ): string {
        $currency = $this->markets->forWorkspace($workspace)->currency;

        if (! $this->catalog->hasPrice($plan, $interval, $currency)) {
            throw new BillingException('price_not_configured');
        }

        $session = new FakeCheckoutSession(
            id: 'cs_test_'.Str::random(32),
            workspaceId: $workspace->id,
            userId: $user->id,
            plan: $plan,
            interval: $interval,
            amount: $this->catalog->amount($plan, $interval, $currency),
            currency: strtolower($currency),
            customerId: $workspace->stripe_customer_id ?? 'cus_fake_'.Str::random(14),
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
            createdAt: time(),
        );

        $this->store->put($session);

        return route('dev.fake-checkout', ['session' => $session->id]);
    }

    public function createPortalSession(Workspace $workspace, string $returnUrl): ?string
    {
        return null;
    }

    public function changePlan(Workspace $workspace, Subscription $subscription, string $plan, BillingInterval $interval): void
    {
        $currency = $this->currency($workspace, $subscription);

        if (! $this->catalog->hasPrice($plan, $interval, $currency)) {
            throw new BillingException('price_not_configured');
        }

        $this->dispatch($subscription, $plan, $interval, $currency, false);
    }

    public function cancelAtPeriodEnd(Workspace $workspace, Subscription $subscription): void
    {
        $this->dispatch($subscription, $subscription->plan, self::interval($subscription), $this->currency($workspace, $subscription), true);
    }

    public function resume(Workspace $workspace, Subscription $subscription): void
    {
        $this->dispatch($subscription, $subscription->plan, self::interval($subscription), $this->currency($workspace, $subscription), false);
    }

    private function dispatch(Subscription $subscription, string $plan, BillingInterval $interval, string $currency, bool $cancelAtPeriodEnd): void
    {
        if ($subscription->stripe_subscription_id === null || $subscription->stripe_customer_id === null) {
            throw new BillingException('no_subscription');
        }

        $this->dispatcher->dispatch($this->events->forSubscriptionUpdate($subscription, $plan, $interval, $currency, $cancelAtPeriodEnd));
    }

    private function currency(Workspace $workspace, Subscription $subscription): string
    {
        return $subscription->currency ?? $this->markets->forWorkspace($workspace)->currency;
    }

    private static function interval(Subscription $subscription): BillingInterval
    {
        return BillingInterval::tryFrom($subscription->interval) ?? BillingInterval::Month;
    }
}
