<?php

declare(strict_types=1);

namespace App\Billing;

use App\Audit\Auditor;
use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\ProcessedStripeEvent;
use App\Models\Subscription;
use App\Models\Workspace;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class StripeEventProcessor
{
    public const string PROCESSED = 'processed';

    public const string DUPLICATE = 'duplicate';

    public const string IGNORED = 'ignored';

    public const array HANDLED_EVENTS = [
        'checkout.session.completed',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'customer.subscription.paused',
        'customer.subscription.resumed',
        'customer.subscription.trial_will_end',
        'invoice.paid',
        'invoice.payment_failed',
    ];

    private const array SUBSCRIPTION_EVENTS = [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'customer.subscription.paused',
        'customer.subscription.resumed',
        'customer.subscription.trial_will_end',
    ];

    private const array NULLABLE_ATTRIBUTES = ['trial_ends_at', 'canceled_at', 'payment_failed_at'];

    public function __construct(
        private PlanCatalog $catalog,
        private Auditor $auditor,
        private BillingNotifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     * @return self::PROCESSED|self::DUPLICATE|self::IGNORED
     */
    public function handle(array $event): string
    {
        $eventId = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');

        if ($eventId === '' || $type === '') {
            throw new \InvalidArgumentException('Stripe event must have id and type.');
        }

        return DB::transaction(function () use ($event, $eventId, $type): string {
            $inserted = ProcessedStripeEvent::query()->insertOrIgnore([
                'event_id' => $eventId,
                'type' => $type,
                'processed_at' => now(),
            ]);

            if ($inserted === 0) {
                return self::DUPLICATE;
            }

            /** @var array<string, mixed> $object */
            $object = Arr::get($event, 'data.object', []);
            $created = (int) ($event['created'] ?? time());

            $workspace = match (true) {
                $type === 'checkout.session.completed' => $this->applyCheckoutCompleted($object, $created),
                in_array($type, self::SUBSCRIPTION_EVENTS, true) => $this->applySubscription($object, $created, $type),
                $type === 'invoice.paid' => $this->applyInvoicePaid($object, $created),
                $type === 'invoice.payment_failed' => $this->applyInvoiceFailed($object, $created),
                default => null,
            };

            if ($workspace === null) {
                Log::info('billing.stripe_event.ignored', ['event_id' => $eventId, 'type' => $type]);

                return self::IGNORED;
            }

            ProcessedStripeEvent::query()->whereKey($eventId)->update(['workspace_id' => $workspace->id]);

            return self::PROCESSED;
        });
    }

    /** @param  array<string, mixed>  $session */
    private function applyCheckoutCompleted(array $session, int $created): ?Workspace
    {
        if (($session['mode'] ?? null) !== 'subscription') {
            return null;
        }

        $customerId = self::string($session['customer'] ?? null);
        $subscriptionId = self::string($session['subscription'] ?? null);
        $workspace = $this->findWorkspace(
            Arr::get($session, 'metadata.workspace_id') ?? ($session['client_reference_id'] ?? null),
            null,
            $customerId,
        );

        if ($workspace === null) {
            return null;
        }

        if ($customerId !== null && $workspace->stripe_customer_id !== $customerId) {
            $workspace->forceFill(['stripe_customer_id' => $customerId])->save();
        }

        $plan = (string) Arr::get($session, 'metadata.plan', '');
        $interval = BillingInterval::tryFrom((string) Arr::get($session, 'metadata.interval', 'month')) ?? BillingInterval::Month;

        if (! $this->catalog->exists($plan)) {
            return null;
        }

        $attributes = [
            'gateway' => self::gateway(Arr::get($session, 'metadata.gateway')),
            'plan' => $plan,
            'interval' => $interval->value,
            'currency' => self::currency($session['currency'] ?? Arr::get($session, 'metadata.currency')),
            'stripe_customer_id' => $customerId,
            'stripe_subscription_id' => $subscriptionId,
        ];

        $existing = Subscription::query()->where('workspace_id', $workspace->id)->first();
        $alreadyLive = $existing !== null
            && $subscriptionId !== null
            && $existing->stripe_subscription_id === $subscriptionId
            && $existing->status->grantsPlan();

        if (! $alreadyLive) {
            $paid = in_array($session['payment_status'] ?? null, ['paid', 'no_payment_required'], true);
            $attributes['status'] = $paid ? SubscriptionStatus::Active : SubscriptionStatus::Incomplete;
        }

        $this->persist($workspace, $created, $attributes, 'checkout.session.completed');

        return $workspace;
    }

    /** @param  array<string, mixed>  $subscription */
    private function applySubscription(array $subscription, int $created, string $type): ?Workspace
    {
        $subscriptionId = self::string($subscription['id'] ?? null);
        $customerId = self::string($subscription['customer'] ?? null);

        $workspace = $this->findWorkspace(Arr::get($subscription, 'metadata.workspace_id'), $subscriptionId, $customerId);

        if ($workspace === null) {
            return null;
        }

        /** @var array<string, mixed> $item */
        $item = Arr::get($subscription, 'items.data.0', []);
        $priceId = self::string(Arr::get($item, 'price.id'));
        $lookupKey = self::string(Arr::get($item, 'price.lookup_key'));
        $byLookup = $lookupKey !== null ? $this->catalog->findByLookupKey($lookupKey) : null;

        $plan = (string) ($byLookup['plan'] ?? Arr::get($subscription, 'metadata.plan') ?? '');
        $interval = BillingInterval::tryFrom((string) (Arr::get($item, 'price.recurring.interval')
            ?? Arr::get($subscription, 'metadata.interval', 'month'))) ?? BillingInterval::Month;

        if (! $this->catalog->exists($plan)) {
            Log::warning('billing.stripe_event.unknown_plan', ['subscription' => $subscriptionId, 'price' => $priceId]);

            return null;
        }

        $status = $type === 'customer.subscription.deleted'
            ? SubscriptionStatus::Canceled
            : SubscriptionStatus::fromStripe((string) ($subscription['status'] ?? 'incomplete'));

        $periodEnd = $subscription['current_period_end'] ?? Arr::get($item, 'current_period_end');

        $attributes = [
            'gateway' => self::gateway(Arr::get($subscription, 'metadata.gateway')),
            'plan' => $plan,
            'interval' => $interval->value,
            'status' => $status,
            'currency' => self::currency(Arr::get($item, 'price.currency') ?? $subscription['currency'] ?? Arr::get($subscription, 'metadata.currency')),
            'stripe_customer_id' => $customerId,
            'stripe_subscription_id' => $subscriptionId,
            'stripe_price_id' => $priceId,
            'current_period_end' => self::timestamp($periodEnd),
            'cancel_at_period_end' => (bool) ($subscription['cancel_at_period_end'] ?? false),
            'trial_ends_at' => self::timestamp($subscription['trial_end'] ?? null),
            'canceled_at' => self::timestamp($subscription['canceled_at'] ?? null)
                ?? ($status === SubscriptionStatus::Canceled ? now() : null),
        ];

        if (in_array($status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true)) {
            $attributes['payment_failed_at'] = null;
        }

        $record = $this->persist($workspace, $created, $attributes, $type);

        if ($customerId !== null && $workspace->stripe_customer_id === null) {
            $workspace->forceFill(['stripe_customer_id' => $customerId])->save();
        }

        if ($type === 'customer.subscription.trial_will_end' && $record !== null && $status === SubscriptionStatus::Trialing) {
            $this->notifier->trialEnding($workspace, $record);
        }

        return $workspace;
    }

    /** @param  array<string, mixed>  $invoice */
    private function applyInvoicePaid(array $invoice, int $created): ?Workspace
    {
        [$workspace, $subscription] = $this->invoiceTarget($invoice);

        if ($workspace === null || $subscription === null) {
            return $workspace;
        }

        $attributes = ['payment_failed_at' => null];

        if (in_array($subscription->status, [SubscriptionStatus::PastDue, SubscriptionStatus::Incomplete], true)) {
            $attributes['status'] = SubscriptionStatus::Active;
        }

        $this->persist($workspace, $created, $attributes, 'invoice.paid');

        return $workspace;
    }

    /** @param  array<string, mixed>  $invoice */
    private function applyInvoiceFailed(array $invoice, int $created): ?Workspace
    {
        [$workspace, $subscription] = $this->invoiceTarget($invoice);

        if ($workspace === null || $subscription === null) {
            return $workspace;
        }

        if (($invoice['billing_reason'] ?? null) === 'subscription_create' || $subscription->status === SubscriptionStatus::Canceled) {
            return $workspace;
        }

        $record = $this->persist($workspace, $created, [
            'status' => SubscriptionStatus::PastDue,
            'payment_failed_at' => Carbon::createFromTimestampUTC($created),
        ], 'invoice.payment_failed');

        if ($record !== null) {
            $this->notifier->paymentFailed($workspace, $record, $invoice);
        }

        return $workspace;
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array{0: Workspace|null, 1: Subscription|null}
     */
    private function invoiceTarget(array $invoice): array
    {
        $subscriptionId = self::string($invoice['subscription'] ?? null)
            ?? self::string(Arr::get($invoice, 'parent.subscription_details.subscription'));

        if ($subscriptionId === null) {
            return [null, null];
        }

        $workspace = $this->findWorkspace(
            Arr::get($invoice, 'parent.subscription_details.metadata.workspace_id')
                ?? Arr::get($invoice, 'subscription_details.metadata.workspace_id'),
            $subscriptionId,
            self::string($invoice['customer'] ?? null),
        );

        if ($workspace === null) {
            return [null, null];
        }

        $subscription = Subscription::query()->where('workspace_id', $workspace->id)->first();

        if ($subscription === null || $subscription->stripe_subscription_id !== $subscriptionId) {
            Log::info('billing.stripe_event.invoice_for_unknown_subscription', ['workspace_id' => $workspace->id, 'subscription' => $subscriptionId]);

            return [$workspace, null];
        }

        return [$workspace, $subscription];
    }

    /** @param  array<string, mixed>  $attributes */
    private function persist(Workspace $workspace, int $eventCreated, array $attributes, string $source): ?Subscription
    {
        $subscription = Subscription::query()->where('workspace_id', $workspace->id)->lockForUpdate()->first();

        if ($subscription !== null && $subscription->last_event_at !== null && $eventCreated < $subscription->last_event_at) {
            Log::info('billing.stripe_event.stale', ['workspace_id' => $workspace->id, 'source' => $source]);

            return null;
        }

        if ($subscription === null && ! isset($attributes['plan'], $attributes['status'])) {
            return null;
        }

        $before = $subscription?->only(['plan', 'status', 'interval', 'cancel_at_period_end']) ?? [];

        $subscription ??= new Subscription(['workspace_id' => $workspace->id]);
        $subscription->fill(array_filter(
            $attributes,
            fn (mixed $value, string $key): bool => $value !== null || in_array($key, self::NULLABLE_ATTRIBUTES, true),
            ARRAY_FILTER_USE_BOTH,
        ));
        $subscription->last_event_at = $eventCreated;
        $subscription->save();

        $after = $subscription->only(['plan', 'status', 'interval', 'cancel_at_period_end']);
        $changes = Auditor::diff($before, $after);

        if ($changes !== []) {
            $this->auditor->record(
                'billing.subscription_changed',
                $subscription,
                $changes,
                ['source' => $source],
                actor: null,
                workspace: $workspace,
                subjectLabel: (string) __("billing.plans.{$subscription->plan}.name"),
            );
        }

        $workspace->unsetRelation('subscription');

        return $subscription;
    }

    private function findWorkspace(mixed $workspaceId, ?string $subscriptionId, ?string $customerId): ?Workspace
    {
        if (is_numeric($workspaceId)) {
            $workspace = Workspace::query()->find((int) $workspaceId);

            if ($workspace !== null) {
                return $workspace;
            }
        }

        if ($subscriptionId !== null) {
            $workspaceIdBySubscription = Subscription::query()
                ->where('stripe_subscription_id', $subscriptionId)
                ->value('workspace_id');

            if ($workspaceIdBySubscription !== null) {
                return Workspace::query()->find((int) $workspaceIdBySubscription);
            }
        }

        if ($customerId !== null) {
            return Workspace::query()->where('stripe_customer_id', $customerId)->first();
        }

        return null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function gateway(mixed $value): string
    {
        return $value === 'fake' ? 'fake' : 'stripe';
    }

    private static function currency(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-zA-Z]{3}$/', $value) === 1 ? strtoupper($value) : null;
    }

    private static function timestamp(mixed $value): ?Carbon
    {
        return is_numeric($value) ? Carbon::createFromTimestampUTC((int) $value) : null;
    }
}
