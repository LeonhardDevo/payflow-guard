<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\ProcessedStripeEvent;
use App\Models\Subscription;
use App\Models\Workspace;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function subscriptionEvent(Workspace $workspace, string $type, string $status, int $created, array $overrides = []): array
{
    return [
        'id' => 'evt_'.bin2hex(random_bytes(8)),
        'object' => 'event',
        'type' => $type,
        'created' => $created,
        'livemode' => false,
        'data' => ['object' => array_replace_recursive([
            'id' => 'sub_123',
            'object' => 'subscription',
            'customer' => 'cus_123',
            'status' => $status,
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'trial_end' => null,
            'metadata' => ['workspace_id' => (string) $workspace->id, 'plan' => 'starter', 'interval' => 'month'],
            'items' => ['data' => [[
                'current_period_end' => $created + 2592000,
                'price' => ['id' => 'price_x', 'recurring' => ['interval' => 'month']],
            ]]],
        ], $overrides)],
    ];
}

it('applies a subscription event once (idempotent by event id)', function (): void {
    $workspace = Workspace::factory()->create();
    $event = subscriptionEvent($workspace, 'customer.subscription.created', 'active', time());

    $this->internal('POST', '/internal/v1/stripe/events', $event)->assertOk()->assertJson(['status' => 'processed']);
    $this->internal('POST', '/internal/v1/stripe/events', $event)->assertOk()->assertJson(['status' => 'duplicate']);

    expect(Subscription::query()->count())->toBe(1)
        ->and(ProcessedStripeEvent::query()->whereKey($event['id'])->value('workspace_id'))->toBe($workspace->id)
        ->and(AuditLog::query()->where('action', 'billing.subscription_changed')->count())->toBe(1);

    $subscription = Subscription::query()->sole();
    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->plan)->toBe('starter')
        ->and($subscription->stripe_subscription_id)->toBe('sub_123')
        ->and($subscription->current_period_end?->timestamp)->toBe($event['created'] + 2592000)
        ->and($workspace->refresh()->stripe_customer_id)->toBe('cus_123')
        ->and($workspace->planKey())->toBe('starter');
});

it('never lets an older event overwrite newer state', function (): void {
    $workspace = Workspace::factory()->create();
    $now = time();

    $this->internal('POST', '/internal/v1/stripe/events', subscriptionEvent($workspace, 'customer.subscription.updated', 'active', $now))->assertOk();
    $this->internal('POST', '/internal/v1/stripe/events', subscriptionEvent($workspace, 'customer.subscription.created', 'incomplete', $now - 10))->assertOk();

    expect(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Active);
});

it('handles cancellation and past_due', function (): void {
    $workspace = Workspace::factory()->create();
    $now = time();

    $this->internal('POST', '/internal/v1/stripe/events', subscriptionEvent($workspace, 'customer.subscription.updated', 'past_due', $now));
    expect($workspace->fresh()->planKey())->toBe('starter');

    $this->internal('POST', '/internal/v1/stripe/events', subscriptionEvent($workspace, 'customer.subscription.updated', 'active', $now + 1, [
        'cancel_at_period_end' => true,
    ]));
    expect(Subscription::query()->sole()->cancel_at_period_end)->toBeTrue();

    $this->internal('POST', '/internal/v1/stripe/events', subscriptionEvent($workspace, 'customer.subscription.deleted', 'canceled', $now + 2));

    $subscription = Subscription::query()->sole();
    expect($subscription->status)->toBe(SubscriptionStatus::Canceled)
        ->and($subscription->canceled_at)->not->toBeNull()
        ->and($workspace->fresh()->planKey())->toBe('free');
});

it('finds the workspace by subscription or customer when metadata is missing', function (): void {
    $workspace = Workspace::factory()->create();
    $this->internal('POST', '/internal/v1/stripe/events', subscriptionEvent($workspace, 'customer.subscription.created', 'active', time()));

    $event = subscriptionEvent($workspace, 'customer.subscription.updated', 'past_due', time() + 5, ['metadata' => ['workspace_id' => null]]);
    $event['data']['object']['metadata'] = ['plan' => 'starter'];

    $this->internal('POST', '/internal/v1/stripe/events', $event)->assertJson(['status' => 'processed']);

    expect(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::PastDue);
});

it('maps the plan from the price lookup_key, which wins over stale metadata', function (): void {
    config(['billing.stripe.catalog' => 'testcat']);
    $workspace = Workspace::factory()->create();

    $event = subscriptionEvent($workspace, 'customer.subscription.created', 'trialing', time());
    $event['data']['object']['metadata'] = ['workspace_id' => (string) $workspace->id, 'plan' => 'starter'];
    $event['data']['object']['items']['data'][0]['price'] = [
        'id' => 'price_business_y',
        'currency' => 'eur',
        'lookup_key' => 'testcat_business_year_eur',
        'recurring' => ['interval' => 'year'],
    ];

    $this->internal('POST', '/internal/v1/stripe/events', $event)->assertJson(['status' => 'processed']);

    expect(Subscription::query()->sole()->only(['plan', 'interval', 'currency']))->toBe(['plan' => 'business', 'interval' => 'year', 'currency' => 'EUR']);
});

it('ignores unrelated or unmappable events but still dedupes them', function (): void {
    $event = ['id' => 'evt_other', 'object' => 'event', 'type' => 'invoice.created', 'created' => time(), 'data' => ['object' => []]];

    $this->internal('POST', '/internal/v1/stripe/events', $event)->assertJson(['status' => 'ignored']);
    $this->internal('POST', '/internal/v1/stripe/events', $event)->assertJson(['status' => 'duplicate']);

    $orphan = subscriptionEvent(Workspace::factory()->make(['id' => 999999]), 'customer.subscription.created', 'active', time());
    $this->internal('POST', '/internal/v1/stripe/events', $orphan)->assertJson(['status' => 'ignored']);

    expect(Subscription::query()->count())->toBe(0);
});

it('rejects bodies that are not Stripe events', function (): void {
    $this->internal('POST', '/internal/v1/stripe/events', ['hello' => 'world'])->assertStatus(422);
});

it('is not reachable without the internal signature', function (): void {
    $this->postJson('/internal/v1/stripe/events', ['id' => 'evt_1', 'object' => 'event', 'type' => 'x'])->assertUnauthorized();
});
