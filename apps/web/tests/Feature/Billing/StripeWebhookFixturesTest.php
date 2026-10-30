<?php

declare(strict_types=1);

use App\Billing\Mail\PaymentFailedMail;
use App\Billing\Mail\TrialEndingMail;
use App\Billing\StripeEventProcessor;
use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceRole;
use App\Models\Subscription;
use App\Models\Workspace;
use Illuminate\Support\Facades\Mail;

/** @return array<string, mixed> */
function stripeFixture(string $type, Workspace $workspace): array
{
    $path = base_path('../../contracts/stripe/events/'.$type.'.json');
    $json = str_replace(
        ['__WORKSPACE_ID__', '__CATALOG__'],
        [(string) $workspace->id, 'testcat'],
        (string) file_get_contents($path),
    );

    return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    config(['billing.stripe.catalog' => 'testcat']);
    Mail::fake();
});

it('ships a fixture for every Stripe event the web handler processes', function (): void {
    foreach (StripeEventProcessor::HANDLED_EVENTS as $type) {
        if (in_array($type, ['customer.subscription.paused', 'customer.subscription.resumed'], true)) {
            continue;
        }

        expect(base_path('../../contracts/stripe/events/'.$type.'.json'))->toBeFile();
    }
});

it('runs the full subscription lifecycle from Stripe fixtures', function (): void {
    [$owner, $workspace] = $this->member(WorkspaceRole::Owner);
    $owner->forceFill(['locale' => 'ru'])->save();
    [$member] = $this->member(WorkspaceRole::Member, $workspace);
    $post = fn (string $type) => $this->internal('POST', '/internal/v1/stripe/events', stripeFixture($type, $workspace));

    $post('checkout.session.completed')->assertJson(['status' => 'processed']);
    expect($workspace->refresh()->stripe_customer_id)->toBe('cus_Fixture0001');

    $post('customer.subscription.created')->assertJson(['status' => 'processed']);
    $subscription = Subscription::query()->sole();
    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->plan)->toBe('pro')
        ->and($subscription->currency)->toBe('USD')
        ->and($subscription->trial_ends_at?->timestamp)->toBe(1768435190);

    $post('customer.subscription.trial_will_end')->assertJson(['status' => 'processed']);
    Mail::assertSent(TrialEndingMail::class, fn (TrialEndingMail $mail): bool => $mail->hasTo($owner->email) && $mail->locale === 'ru');
    Mail::assertNotSent(TrialEndingMail::class, fn (TrialEndingMail $mail): bool => $mail->hasTo($member->email));

    $post('customer.subscription.updated')->assertJson(['status' => 'processed']);
    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->plan)->toBe('business')
        ->and($workspace->refresh()->planKey())->toBe('business');

    $post('invoice.payment_failed')->assertJson(['status' => 'processed']);
    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->payment_failed_at?->timestamp)->toBe(1769904000)
        ->and($workspace->refresh()->planKey())->toBe('business');
    Mail::assertSent(PaymentFailedMail::class, fn (PaymentFailedMail $mail): bool => $mail->hasTo($owner->email)
        && $mail->amount === 4900
        && $mail->currency === 'USD'
        && $mail->invoiceUrl === 'https://invoice.stripe.com/i/acct_fixture/test_fixture0002'
        && $mail->nextAttemptAt?->timestamp === 1770163200);
    Mail::assertSentCount(2);

    $post('invoice.payment_failed')->assertJson(['status' => 'duplicate']);
    Mail::assertSentCount(2);

    $this->actingAs($owner)->get(route('settings.billing'))
        ->assertInertia(fn ($page) => $page
            ->where('billing.alert.status', 'past_due')
            ->where('subscription.status', 'past_due')
            ->where('subscription.payment_failed_at', '2026-02-01T00:00:00+00:00'));

    $post('invoice.paid')->assertJson(['status' => 'processed']);
    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->payment_failed_at)->toBeNull();

    $post('customer.subscription.deleted')->assertJson(['status' => 'processed']);
    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Canceled)
        ->and($workspace->refresh()->planKey())->toBe('free');
});

it('renders the dunning e-mails in both locales', function (): void {
    [$owner, $workspace] = $this->member(WorkspaceRole::Owner);
    $this->internal('POST', '/internal/v1/stripe/events', stripeFixture('customer.subscription.created', $workspace));
    $subscription = Subscription::query()->sole();

    foreach (['en' => 'We could not process your payment', 'ru' => 'Платёж не прошёл'] as $locale => $heading) {
        $mail = (new PaymentFailedMail($workspace, $subscription, 149000, 'RUB', null, now()->addDays(3)))->locale($locale);
        $html = $mail->render();

        expect($html)->toContain($heading)
            ->and($html)->toContain('1'.($locale === 'ru' ? "\u{00A0}" : ',').'490')
            ->and($html)->not->toContain('https://invoice.stripe.com');
    }

    $trial = (new TrialEndingMail($workspace, $subscription))->locale('ru')->render();
    expect($trial)->toContain('Пробный период скоро закончится');
});

it('ignores first-invoice failures and invoices of an unknown subscription', function (): void {
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    $this->internal('POST', '/internal/v1/stripe/events', stripeFixture('customer.subscription.updated', $workspace));

    $first = stripeFixture('invoice.payment_failed', $workspace);
    $first['id'] = 'evt_first_invoice';
    $first['data']['object']['billing_reason'] = 'subscription_create';
    $this->internal('POST', '/internal/v1/stripe/events', $first)->assertJson(['status' => 'processed']);

    $foreign = stripeFixture('invoice.payment_failed', $workspace);
    $foreign['id'] = 'evt_foreign_invoice';
    $foreign['data']['object']['parent']['subscription_details']['subscription'] = 'sub_other';
    $this->internal('POST', '/internal/v1/stripe/events', $foreign)->assertJson(['status' => 'processed']);

    expect(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Active);
    Mail::assertNothingSent();
});

it('does not let a trial checkout completion overwrite the trialing status', function (): void {
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    $this->internal('POST', '/internal/v1/stripe/events', stripeFixture('customer.subscription.created', $workspace));

    $checkout = stripeFixture('checkout.session.completed', $workspace);
    $checkout['created'] = 1767225700;
    $checkout['data']['object']['payment_status'] = 'no_payment_required';
    $this->internal('POST', '/internal/v1/stripe/events', $checkout)->assertJson(['status' => 'processed']);

    expect(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Trialing);
});

it('shows a trial-ending banner close to the end of the trial', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    Subscription::query()->create([
        'workspace_id' => $workspace->id,
        'plan' => 'pro',
        'status' => SubscriptionStatus::Trialing,
        'interval' => 'month',
        'trial_ends_at' => now()->addDays(2),
    ]);

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('billing.alert.status', 'trial_ending'));

    Subscription::query()->update(['trial_ends_at' => now()->addDays(10)]);
    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('billing.alert', null));
});
