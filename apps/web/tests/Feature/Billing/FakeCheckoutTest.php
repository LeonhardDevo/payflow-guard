<?php

declare(strict_types=1);

use App\Billing\StripeSignature;
use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceRole;
use App\Models\Subscription;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Stripe\WebhookSignature;

function startFakeCheckout(object $test, string $plan = 'pro', string $interval = 'month'): string
{
    $response = $test->post(route('settings.billing.checkout'), ['plan' => $plan, 'interval' => $interval], ['X-Inertia' => 'true']);
    $response->assertStatus(409);

    $location = (string) $response->headers->get('X-Inertia-Location');
    expect($location)->toMatch('#/dev/fake-checkout/cs_test_[A-Za-z0-9]{32}$#');

    return substr($location, strrpos($location, '/') + 1);
}

it('redirects checkout to the fake hosted page', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $session = startFakeCheckout($this, 'pro', 'year');

    $this->get(route('dev.fake-checkout', $session))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dev/FakeCheckout', false)
            ->where('session.id', $session)
            ->where('session.kind', 'subscription')
            ->where('session.amount', 490)
            ->where('session.currency', 'USD')
            ->where('session.workspace', ['id' => $workspace->id, 'name' => $workspace->name])
            ->where('session.success_url', route('settings.billing', ['checkout' => 'success']))
            ->where('session.cancel_url', route('settings.billing', ['checkout' => 'cancelled'])));
});

it('rejects the free plan and unknown intervals', function (): void {
    $this->signInMember(WorkspaceRole::Owner);

    $this->post(route('settings.billing.checkout'), ['plan' => 'free', 'interval' => 'month'])->assertSessionHasErrors('plan');
    $this->post(route('settings.billing.checkout'), ['plan' => 'pro', 'interval' => 'week'])->assertSessionHasErrors('interval');
});

it('pays: posts Stripe-signed, Stripe-shaped events to the worker webhook', function (): void {
    Http::fake(['worker.test/webhooks/stripe' => Http::response(['received' => true])]);
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $session = startFakeCheckout($this);

    $this->post(route('dev.fake-checkout.pay', $session))
        ->assertRedirect(route('settings.billing', ['checkout' => 'success']))
        ->assertSessionHas('success');

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(3);

    $types = [];

    foreach ($recorded as [$request]) {
        /** @var HttpRequest $request */
        expect($request->url())->toBe('http://worker.test/webhooks/stripe')
            ->and($request->hasHeader('Stripe-Signature'))->toBeTrue()
            ->and(StripeSignature::verify($request->body(), $request->header('Stripe-Signature')[0], 'whsec_test_secret'))->toBeTrue()
            ->and(WebhookSignature::verifyHeader($request->body(), $request->header('Stripe-Signature')[0], 'whsec_test_secret'))->toBeTrue();

        $event = json_decode($request->body(), true);
        $types[] = $event['type'];

        expect($event)->toHaveKeys(['id', 'object', 'api_version', 'created', 'livemode', 'type', 'data'])
            ->and($event['object'])->toBe('event')
            ->and($event['livemode'])->toBeFalse()
            ->and($event['data']['object']['metadata'])->toBe([
                'workspace_id' => (string) $workspace->id, 'plan' => 'pro', 'interval' => 'month', 'currency' => 'USD', 'gateway' => 'fake',
            ]);
    }

    expect($types)->toBe(['customer.subscription.created', 'checkout.session.completed', 'customer.subscription.updated']);

    $this->get(route('dev.fake-checkout', $session))->assertNotFound();
});

it('closes the loop: worker forwards the events → subscription active', function (): void {
    Http::fake(['worker.test/*' => Http::response(['received' => true])]);
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $this->post(route('dev.fake-checkout.pay', startFakeCheckout($this)));

    foreach (Http::recorded()->reverse() as [$request]) {
        $this->internal('POST', '/internal/v1/stripe/events', json_decode($request->body(), true))->assertOk();
    }

    $subscription = Subscription::query()->sole();
    expect($subscription->workspace_id)->toBe($workspace->id)
        ->and($subscription->plan)->toBe('pro')
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->interval)->toBe('month')
        ->and($subscription->current_period_end?->isFuture())->toBeTrue()
        ->and($workspace->refresh()->planKey())->toBe('pro')
        ->and($workspace->stripe_customer_id)->toStartWith('cus_fake_');

    $this->get(route('settings.billing'))
        ->assertInertia(fn ($page) => $page
            ->where('subscription.plan', 'pro')
            ->where('subscription.status', 'active')
            ->where('currentPlan', 'pro')
            ->where('usage.documents.limit', 150));
});

it('reports an unreachable worker without changing anything', function (): void {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));
    $this->signInMember(WorkspaceRole::Owner);
    $session = startFakeCheckout($this);

    $this->from(route('dev.fake-checkout', $session))
        ->post(route('dev.fake-checkout.pay', $session))
        ->assertRedirect(route('dev.fake-checkout', $session))
        ->assertSessionHas('error', __('billing.errors.worker_unavailable'));

    expect(Subscription::query()->count())->toBe(0);
    $this->get(route('dev.fake-checkout', $session))->assertOk();
});

it('reports a rejected webhook', function (): void {
    Http::fake(['worker.test/*' => Http::response(['error' => 'bad signature'], 400)]);
    $this->signInMember(WorkspaceRole::Owner);
    $session = startFakeCheckout($this);

    $this->post(route('dev.fake-checkout.pay', $session))->assertSessionHas('error', __('billing.errors.worker_rejected'));
});

it('protects the fake checkout session', function (): void {
    $this->signInMember(WorkspaceRole::Owner);
    $session = startFakeCheckout($this);

    $this->signInMember(WorkspaceRole::Owner);
    $this->get(route('dev.fake-checkout', $session))->assertForbidden();

    $this->get('/dev/fake-checkout/cs_test_unknown')->assertNotFound();
});

it('does not exist with the stripe driver', function (): void {
    config(['billing.driver' => 'stripe']);
    $this->signInMember(WorkspaceRole::Owner);

    $this->get('/dev/fake-checkout/cs_test_abc')->assertNotFound();
});

it('answers the fake billing portal with a flash', function (): void {
    $this->signInMember(WorkspaceRole::Owner);

    $this->post(route('settings.billing.portal'))->assertSessionHas('success', __('billing.portal_unavailable_fake'));
});
