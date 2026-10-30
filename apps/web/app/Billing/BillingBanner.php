<?php

declare(strict_types=1);

namespace App\Billing;

use App\Billing\Markets\MarketResolver;
use App\Enums\SubscriptionStatus;
use App\Models\Workspace;

final readonly class BillingBanner
{
    public function __construct(
        private BillingGateways $gateways,
        private MarketResolver $markets,
    ) {}

    /** @return array{driver: string, alert: array{status: string, at: string|null}|null} */
    public function for(?Workspace $workspace): array
    {
        if ($workspace === null) {
            return ['driver' => $this->markets->forcedGateway() ?? (string) config('billing.markets.default_gateway', 'stripe'), 'alert' => null];
        }

        return [
            'driver' => $this->gateways->driverNameFor($workspace),
            'alert' => $this->alert($workspace),
        ];
    }

    /** @return array{status: string, at: string|null}|null */
    private function alert(Workspace $workspace): ?array
    {
        $subscription = $workspace->subscription;

        if ($subscription === null) {
            return null;
        }

        if ($subscription->status === SubscriptionStatus::PastDue) {
            return ['status' => 'past_due', 'at' => $subscription->payment_failed_at?->toIso8601String()];
        }

        $noticeDays = (int) config('billing.trial_ending_notice_days', 3);

        if ($subscription->status === SubscriptionStatus::Trialing
            && $subscription->trial_ends_at !== null
            && $subscription->trial_ends_at->isFuture()
            && $subscription->trial_ends_at->lessThanOrEqualTo(now()->addDays($noticeDays))) {
            return ['status' => 'trial_ending', 'at' => $subscription->trial_ends_at->toIso8601String()];
        }

        return null;
    }
}
