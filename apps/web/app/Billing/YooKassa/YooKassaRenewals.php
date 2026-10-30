<?php

declare(strict_types=1);

namespace App\Billing\YooKassa;

use App\Billing\BillingException;
use App\Billing\BillingNotifier;
use App\Billing\Markets\MarketResolver;
use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\Workspace;
use Illuminate\Support\Facades\Log;

final readonly class YooKassaRenewals
{
    public function __construct(
        private YooKassaClient $client,
        private YooKassaGateway $gateway,
        private YooKassaPaymentProcessor $processor,
        private YooKassaSubscriptions $subscriptions,
        private BillingNotifier $notifier,
        private MarketResolver $markets,
    ) {}

    /** @return array{due: int, charged: int, succeeded: int, failed: int, canceled: int, skipped: int, errors: int} */
    public function run(int $limit = 200): array
    {
        $summary = ['due' => 0, 'charged' => 0, 'succeeded' => 0, 'failed' => 0, 'canceled' => 0, 'skipped' => 0, 'errors' => 0];

        if (! $this->markets->gatewayAvailable('yookassa')) {
            return $summary;
        }

        $due = Subscription::query()
            ->where('gateway', 'yookassa')
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
            ->where('current_period_end', '<=', now())
            ->orderBy('current_period_end')
            ->limit($limit)
            ->get();

        foreach ($due as $subscription) {
            $summary['due']++;
            $outcome = $this->renew($subscription);
            $summary[$outcome]++;
        }

        return $summary;
    }

    /** @return 'charged'|'succeeded'|'failed'|'canceled'|'skipped'|'errors' */
    public function renew(Subscription $subscription): string
    {
        $workspace = $subscription->workspace;

        if ($workspace === null) {
            return 'skipped';
        }

        if ($subscription->cancel_at_period_end || $this->graceExpired($subscription)) {
            $this->subscriptions->apply($workspace, [
                'status' => SubscriptionStatus::Canceled,
                'canceled_at' => now(),
                'cancel_at_period_end' => false,
                'scheduled_plan' => null,
                'scheduled_interval' => null,
            ], $subscription->cancel_at_period_end ? 'yookassa.period_ended' : 'yookassa.grace_expired');

            return 'canceled';
        }

        $retryAfter = now()->subHours(max(1, (int) config('billing.yookassa.retry_hours', 24)));

        if ($subscription->last_charge_attempt_at !== null && $subscription->last_charge_attempt_at->greaterThan($retryAfter)) {
            return 'skipped';
        }

        if ($subscription->provider_payment_method_id === null) {
            $this->markFailed($workspace, $subscription);

            return 'failed';
        }

        $plan = $subscription->scheduled_plan ?? $subscription->plan;
        $interval = BillingInterval::tryFrom((string) ($subscription->scheduled_interval ?? $subscription->interval)) ?? BillingInterval::Month;
        $currency = strtoupper((string) $subscription->currency);
        $periodEnd = (string) $subscription->current_period_end?->getTimestamp();

        $subscription->forceFill(['last_charge_attempt_at' => now()])->save();

        try {
            $amount = $this->gateway->priceFor($plan, $interval, $currency);
            $payload = [
                'amount' => YooKassaClient::amount($amount, $currency),
                'capture' => true,
                'payment_method_id' => $subscription->provider_payment_method_id,
                'description' => $this->gateway->description($workspace, $plan, $interval),
                'metadata' => [
                    'kind' => YooKassaGateway::KIND_RENEWAL,
                    'workspace_id' => (string) $workspace->id,
                    'plan' => $plan,
                    'interval' => $interval->value,
                    'currency' => $currency,
                    'period_end' => $periodEnd,
                ],
            ];
            $receipt = $this->gateway->receipt($this->notifier->recipients($workspace)->first()?->email, $plan, $interval, $amount, $currency);

            if ($receipt !== null) {
                $payload['receipt'] = $receipt;
            }

            $attempt = (string) intdiv(time(), max(1, (int) config('billing.yookassa.retry_hours', 24)) * 3600);
            $payment = $this->client->createPayment(
                $payload,
                YooKassaGateway::key('renewal', (string) $subscription->id, $periodEnd, $attempt),
            );
        } catch (BillingException $exception) {
            Log::warning('billing.yookassa.renewal_failed', ['subscription_id' => $subscription->id, 'reason' => $exception->reason]);

            return 'errors';
        }

        $result = $this->processor->apply($payment);
        $status = is_string($payment['status'] ?? null) ? $payment['status'] : '';

        return match (true) {
            $result === YooKassaPaymentProcessor::PROCESSED && $status === 'succeeded' => 'succeeded',
            $result === YooKassaPaymentProcessor::PROCESSED && $status === 'canceled' => 'failed',
            default => 'charged',
        };
    }

    private function graceExpired(Subscription $subscription): bool
    {
        return $subscription->status === SubscriptionStatus::PastDue
            && $subscription->payment_failed_at !== null
            && $subscription->payment_failed_at->lessThan(now()->subDays(max(1, (int) config('billing.yookassa.grace_days', 7))));
    }

    private function markFailed(Workspace $workspace, Subscription $subscription): void
    {
        $firstFailure = $subscription->payment_failed_at === null;
        $record = $this->subscriptions->apply($workspace, [
            'status' => SubscriptionStatus::PastDue,
            'payment_failed_at' => $subscription->payment_failed_at ?? now(),
            'last_charge_attempt_at' => now(),
        ], 'yookassa.no_payment_method');

        if ($firstFailure) {
            $this->notifier->paymentFailed($workspace, $record, ['currency' => $subscription->currency]);
        }
    }
}
