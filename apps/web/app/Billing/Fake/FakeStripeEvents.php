<?php

declare(strict_types=1);

namespace App\Billing\Fake;

use App\Billing\PlanCatalog;
use App\Enums\BillingInterval;
use App\Models\Subscription;
use Illuminate\Support\Str;

final readonly class FakeStripeEvents
{
    public const string API_VERSION = '2025-09-30.clover';

    public function __construct(private PlanCatalog $catalog) {}

    /** @return list<array<string, mixed>> */
    public function forPaidCheckout(FakeCheckoutSession $session, ?int $now = null): array
    {
        $now ??= time();
        $subscriptionId = 'sub_fake_'.Str::random(24);
        $periodEnd = self::periodEnd($session->interval, $now);

        $metadata = [
            'workspace_id' => (string) $session->workspaceId,
            'plan' => $session->plan,
            'interval' => $session->interval->value,
            'currency' => strtoupper($session->currency),
            'gateway' => 'fake',
        ];

        $subscription = fn (string $status): array => $this->subscriptionObject(
            id: $subscriptionId,
            customerId: $session->customerId,
            status: $status,
            plan: $session->plan,
            interval: $session->interval,
            currency: $session->currency,
            amount: $session->amount,
            metadata: $metadata,
            start: $now,
            periodEnd: $periodEnd,
            cancelAtPeriodEnd: false,
        );

        $checkoutSession = [
            'id' => $session->id,
            'object' => 'checkout.session',
            'mode' => 'subscription',
            'status' => 'complete',
            'payment_status' => 'paid',
            'customer' => $session->customerId,
            'subscription' => $subscriptionId,
            'client_reference_id' => (string) $session->workspaceId,
            'amount_total' => $session->amount,
            'currency' => $session->currency,
            'livemode' => false,
            'metadata' => $metadata,
            'success_url' => $session->successUrl,
            'cancel_url' => $session->cancelUrl,
            'created' => $session->createdAt,
        ];

        return [
            $this->event('customer.subscription.created', $subscription('incomplete'), $now),
            $this->event('checkout.session.completed', $checkoutSession, $now + 1),
            $this->event('customer.subscription.updated', $subscription('active'), $now + 1, [
                'status' => 'incomplete',
            ]),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function forSubscriptionUpdate(
        Subscription $subscription,
        string $plan,
        BillingInterval $interval,
        string $currency,
        bool $cancelAtPeriodEnd,
        ?int $now = null,
    ): array {
        $now = max($now ?? time(), (int) $subscription->last_event_at);
        $changed = $plan !== $subscription->plan || $interval->value !== $subscription->interval;
        $periodEnd = $changed || $subscription->current_period_end === null
            ? self::periodEnd($interval, $now)
            : $subscription->current_period_end->getTimestamp();

        $object = $this->subscriptionObject(
            id: (string) $subscription->stripe_subscription_id,
            customerId: (string) $subscription->stripe_customer_id,
            status: $subscription->status->value,
            plan: $plan,
            interval: $interval,
            currency: $currency,
            amount: $this->catalog->amount($plan, $interval, $currency),
            metadata: [
                'workspace_id' => (string) $subscription->workspace_id,
                'plan' => $plan,
                'interval' => $interval->value,
                'currency' => strtoupper($currency),
                'gateway' => 'fake',
            ],
            start: $subscription->created_at?->getTimestamp() ?? $now,
            periodEnd: $periodEnd,
            cancelAtPeriodEnd: $cancelAtPeriodEnd,
        );

        return [
            $this->event('customer.subscription.updated', $object, $now, [
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
            ]),
        ];
    }

    /**
     * @param  array<string, string>  $metadata
     * @return array<string, mixed>
     */
    private function subscriptionObject(
        string $id,
        string $customerId,
        string $status,
        string $plan,
        BillingInterval $interval,
        string $currency,
        int $amount,
        array $metadata,
        int $start,
        int $periodEnd,
        bool $cancelAtPeriodEnd,
    ): array {
        $currency = strtolower($currency);

        return [
            'id' => $id,
            'object' => 'subscription',
            'customer' => $customerId,
            'status' => $status,
            'currency' => $currency,
            'cancel_at_period_end' => $cancelAtPeriodEnd,
            'canceled_at' => null,
            'created' => $start,
            'start_date' => $start,
            'trial_end' => null,
            'livemode' => false,
            'metadata' => $metadata,
            'items' => [
                'object' => 'list',
                'data' => [[
                    'id' => 'si_fake_'.substr(md5($id), 0, 14),
                    'object' => 'subscription_item',
                    'quantity' => 1,
                    'current_period_start' => $start,
                    'current_period_end' => $periodEnd,
                    'price' => [
                        'id' => 'price_fake_'.$plan.'_'.$interval->value.'_'.$currency,
                        'object' => 'price',
                        'currency' => $currency,
                        'unit_amount' => $amount,
                        'lookup_key' => $this->catalog->lookupKey($plan, $interval, strtoupper($currency)),
                        'recurring' => ['interval' => $interval->value, 'interval_count' => 1],
                    ],
                ]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>|null  $previousAttributes
     * @return array<string, mixed>
     */
    private function event(string $type, array $object, int $created, ?array $previousAttributes = null): array
    {
        $data = ['object' => $object];

        if ($previousAttributes !== null) {
            $data['previous_attributes'] = $previousAttributes;
        }

        return [
            'id' => 'evt_fake_'.Str::random(24),
            'object' => 'event',
            'api_version' => self::API_VERSION,
            'created' => $created,
            'livemode' => false,
            'pending_webhooks' => 1,
            'request' => ['id' => null, 'idempotency_key' => null],
            'type' => $type,
            'data' => $data,
        ];
    }

    private static function periodEnd(BillingInterval $interval, int $from): int
    {
        return (int) strtotime($interval === BillingInterval::Year ? '+1 year' : '+1 month', $from);
    }
}
