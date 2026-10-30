<?php

declare(strict_types=1);

namespace App\Billing\YooKassa;

use App\Billing\BillingNotifier;
use App\Billing\PlanCatalog;
use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\ProcessedStripeEvent;
use App\Models\Subscription;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class YooKassaPaymentProcessor
{
    public const string PROCESSED = 'processed';

    public const string DUPLICATE = 'duplicate';

    public const string IGNORED = 'ignored';

    public const string PENDING = 'pending';

    public function __construct(
        private YooKassaClient $client,
        private PlanCatalog $catalog,
        private YooKassaSubscriptions $subscriptions,
        private BillingNotifier $notifier,
    ) {}

    /** @return self::PROCESSED|self::DUPLICATE|self::IGNORED|self::PENDING */
    public function handle(string $paymentId): string
    {
        return $this->apply($this->client->getPayment($paymentId));
    }

    /**
     * @param  array<string, mixed>  $payment
     * @return self::PROCESSED|self::DUPLICATE|self::IGNORED|self::PENDING
     */
    public function apply(array $payment): string
    {
        $id = is_string($payment['id'] ?? null) ? $payment['id'] : '';
        $status = is_string($payment['status'] ?? null) ? $payment['status'] : '';

        if ($id === '' || ! in_array($status, ['succeeded', 'canceled'], true)) {
            return self::PENDING;
        }

        return DB::transaction(function () use ($payment, $id, $status): string {
            $inserted = ProcessedStripeEvent::query()->insertOrIgnore([
                'event_id' => 'yookassa:'.$id,
                'type' => 'payment.'.$status,
                'processed_at' => now(),
            ]);

            if ($inserted === 0) {
                return self::DUPLICATE;
            }

            $kind = (string) Arr::get($payment, 'metadata.kind', '');
            $workspace = Workspace::query()->find((int) Arr::get($payment, 'metadata.workspace_id', 0));

            if ($workspace === null || ! in_array($kind, [YooKassaGateway::KIND_INITIAL, YooKassaGateway::KIND_RENEWAL], true)) {
                Log::info('billing.yookassa.payment_ignored', ['payment_id' => $id, 'kind' => $kind]);

                return self::IGNORED;
            }

            $applied = match (true) {
                $kind === YooKassaGateway::KIND_INITIAL && $status === 'succeeded' => $this->initialSucceeded($workspace, $payment),
                $kind === YooKassaGateway::KIND_RENEWAL && $status === 'succeeded' => $this->renewalSucceeded($workspace, $payment),
                $kind === YooKassaGateway::KIND_RENEWAL => $this->renewalFailed($workspace, $payment),
                default => false,
            };

            if (! $applied) {
                Log::info('billing.yookassa.payment_not_applied', ['payment_id' => $id, 'kind' => $kind, 'status' => $status]);

                return self::IGNORED;
            }

            ProcessedStripeEvent::query()->whereKey('yookassa:'.$id)->update(['workspace_id' => $workspace->id]);

            return self::PROCESSED;
        });
    }

    /** @param  array<string, mixed>  $payment */
    private function initialSucceeded(Workspace $workspace, array $payment): bool
    {
        $plan = (string) Arr::get($payment, 'metadata.plan', '');
        $interval = BillingInterval::tryFrom((string) Arr::get($payment, 'metadata.interval', ''));
        $currency = strtoupper((string) Arr::get($payment, 'amount.currency', ''));

        if (! $this->catalog->exists($plan) || $interval === null || ! $this->paidInFull($payment, $plan, $interval, $currency)) {
            return false;
        }

        $paidAt = self::time($payment['captured_at'] ?? $payment['created_at'] ?? null);

        $this->subscriptions->apply($workspace, [
            'gateway' => 'yookassa',
            'plan' => $plan,
            'interval' => $interval->value,
            'currency' => $currency,
            'status' => SubscriptionStatus::Active,
            'current_period_end' => self::advance($paidAt, $interval),
            'cancel_at_period_end' => false,
            'trial_ends_at' => null,
            'canceled_at' => null,
            'payment_failed_at' => null,
            'scheduled_plan' => null,
            'scheduled_interval' => null,
            'stripe_subscription_id' => null,
            'stripe_price_id' => null,
            'provider_payment_method_id' => self::savedMethod($payment),
            'last_event_at' => $paidAt->getTimestamp(),
        ], 'yookassa.payment.succeeded');

        return true;
    }

    /** @param  array<string, mixed>  $payment */
    private function renewalSucceeded(Workspace $workspace, array $payment): bool
    {
        $subscription = $this->renewalTarget($workspace, $payment);

        if ($subscription === null || $subscription->current_period_end === null) {
            return false;
        }

        $plan = (string) Arr::get($payment, 'metadata.plan', $subscription->plan);
        $interval = BillingInterval::tryFrom((string) Arr::get($payment, 'metadata.interval', $subscription->interval)) ?? BillingInterval::Month;
        $currency = strtoupper((string) Arr::get($payment, 'amount.currency', ''));

        if (! $this->catalog->exists($plan) || ! $this->paidInFull($payment, $plan, $interval, $currency)) {
            return false;
        }

        $this->subscriptions->apply($workspace, [
            'plan' => $plan,
            'interval' => $interval->value,
            'status' => SubscriptionStatus::Active,
            'current_period_end' => self::advance(CarbonImmutable::instance($subscription->current_period_end), $interval),
            'payment_failed_at' => null,
            'scheduled_plan' => null,
            'scheduled_interval' => null,
            'provider_payment_method_id' => self::savedMethod($payment) ?? $subscription->provider_payment_method_id,
            'last_event_at' => self::time($payment['captured_at'] ?? $payment['created_at'] ?? null)->getTimestamp(),
        ], 'yookassa.renewal.succeeded');

        return true;
    }

    /** @param  array<string, mixed>  $payment */
    private function renewalFailed(Workspace $workspace, array $payment): bool
    {
        $subscription = $this->renewalTarget($workspace, $payment);

        if ($subscription === null) {
            return false;
        }

        $reason = (string) Arr::get($payment, 'cancellation_details.reason', '');
        $firstFailure = $subscription->payment_failed_at === null;

        $record = $this->subscriptions->apply($workspace, [
            'status' => SubscriptionStatus::PastDue,
            'payment_failed_at' => $subscription->payment_failed_at ?? now(),
            'provider_payment_method_id' => $reason === 'permission_revoked' ? null : $subscription->provider_payment_method_id,
        ], 'yookassa.renewal.canceled');

        if ($firstFailure) {
            $this->notifier->paymentFailed($workspace, $record, [
                'amount_due' => YooKassaClient::minorUnits($payment['amount'] ?? null),
                'currency' => Arr::get($payment, 'amount.currency'),
            ]);
        }

        return true;
    }

    /** @param  array<string, mixed>  $payment */
    private function renewalTarget(Workspace $workspace, array $payment): ?Subscription
    {
        $subscription = Subscription::query()->where('workspace_id', $workspace->id)->first();
        $periodEnd = Arr::get($payment, 'metadata.period_end');

        if ($subscription === null
            || $subscription->gateway !== 'yookassa'
            || $subscription->status === SubscriptionStatus::Canceled
            || $subscription->current_period_end === null
            || (string) $subscription->current_period_end->getTimestamp() !== (string) $periodEnd) {
            return null;
        }

        return $subscription;
    }

    /** @param  array<string, mixed>  $payment */
    private function paidInFull(array $payment, string $plan, BillingInterval $interval, string $currency): bool
    {
        if (! $this->catalog->hasPrice($plan, $interval, $currency) || ($payment['paid'] ?? false) !== true) {
            return false;
        }

        $amount = YooKassaClient::minorUnits($payment['amount'] ?? null);

        return $amount !== null && $amount >= $this->catalog->amount($plan, $interval, $currency);
    }

    /** @param  array<string, mixed>  $payment */
    private static function savedMethod(array $payment): ?string
    {
        $method = $payment['payment_method'] ?? null;

        if (! is_array($method) || ($method['saved'] ?? false) !== true || ! is_string($method['id'] ?? null)) {
            return null;
        }

        return $method['id'];
    }

    private static function time(mixed $value): CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : CarbonImmutable::now('UTC');
    }

    private static function advance(CarbonImmutable $from, BillingInterval $interval): CarbonImmutable
    {
        return $interval === BillingInterval::Year ? $from->addYearNoOverflow() : $from->addMonthNoOverflow();
    }
}
