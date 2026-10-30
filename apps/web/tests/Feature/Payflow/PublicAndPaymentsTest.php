<?php

declare(strict_types=1);

use App\Billing\Payments\FakePaymentGateway;
use App\Billing\Payments\PaymentLinks;
use App\Billing\Payments\PaymentStatus as LinkStatus;
use App\Domain\Payments\Models\InvoicePaymentLink;
use App\Domain\Payments\Models\Payment;
use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Enums\WorkspaceRole;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Payflow\Support\Payflow;

beforeEach(function (): void {
    Storage::fake('local');
    Cache::flush();
    Payflow::freeze('2026-10-22T10:00:00Z');
    [$this->user, $this->workspace] = $this->signInMember(WorkspaceRole::Owner);
    Payflow::pro($this->workspace);
    Payflow::fakeAccount($this->workspace);
    $this->client = Payflow::client($this->workspace, ['name' => 'Greenline Fitness']);
    $this->invoice = Payflow::invoice($this->workspace, $this->user, $this->client, '2026-10-13', '2026-10-27', [['Landing page A/B tests', '1', 235000]]);
});

it('renders the public invoice for guests, records views and never exposes risk data', function (): void {
    auth()->logout();

    $this->get(route('public.invoices.show', ['token' => $this->invoice->public_token]))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/Invoice')
            ->where('invoice.number', 'INV-2026-0001')
            ->where('invoice.display_status', 'open')
            ->where('invoice.balance_due', ['minor' => 235000, 'currency' => 'USD'])
            ->missing('invoice.risk')
            ->where('payable', true)
            ->where('pay_label', 'demo')
            ->where('provider_mode', 'test')
            ->has('legal', 5)
            ->has('company')
            ->where('client.name', 'Greenline Fitness'));

    expect($this->invoice->refresh()->view_count)->toBe(1)->and($this->invoice->first_viewed_at)->not->toBeNull();

    $this->actingAs($this->user)->get(route('public.invoices.show', ['token' => $this->invoice->public_token]))->assertOk();
    expect($this->invoice->refresh()->view_count)->toBe(1);

    WorkspaceProvisioner::settingsFor($this->workspace)->forceFill(['track_invoice_views' => false])->save();
    auth()->logout();
    $this->get(route('public.invoices.show', ['token' => $this->invoice->public_token]))->assertOk();
    expect($this->invoice->refresh()->view_count)->toBe(1);

    $this->get(route('public.invoices.pdf', ['token' => $this->invoice->public_token]))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->getJson(route('public.invoices.status', ['token' => $this->invoice->public_token]))->assertOk()->assertJson(['status' => 'open', 'display_status' => 'open']);
});

it('pays through the fake checkout and records the provider payment exactly once', function (): void {
    auth()->logout();
    $link = InvoicePaymentLink::query()->where('invoice_id', $this->invoice->id)->where('active', true)->sole();

    $this->post(route('public.invoices.pay', ['token' => $this->invoice->public_token]), [], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', $link->url);

    $this->get($link->url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('public/PaymentThanks')->where('invoice.status', 'paid'));

    $this->invoice->refresh();
    $payment = Payment::query()->sole();

    expect($this->invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($payment->provider->value)->toBe('fake')
        ->and($payment->method->value)->toBe('payment_link')
        ->and($payment->amount_minor)->toBe(235000)
        ->and($link->refresh()->active)->toBeFalse();

    app(PaymentLinks::class)->transition($link, LinkStatus::Succeeded, 'replay');
    expect(Payment::query()->count())->toBe(1);

    FakePaymentGateway::settle($link->provider_id, LinkStatus::Refunded);
    app(PaymentLinks::class)->refresh($link);

    expect($payment->refresh()->status->value)->toBe('refunded')
        ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Open)
        ->and($this->invoice->paid_at)->toBeNull();
});

it('lists sync candidates and syncs links through the internal API', function (): void {
    Payflow::freeze('2026-10-22T12:00:00Z');
    $candidates = $this->internal('GET', '/internal/v1/payflow/payment-links/sync-candidates?limit=10')->assertOk()->json('data');
    $link = InvoicePaymentLink::query()->where('invoice_id', $this->invoice->id)->where('active', true)->sole();

    expect($candidates)->toBe([['id' => $link->id, 'workspace_id' => $this->workspace->id]]);

    FakePaymentGateway::settle($link->provider_id, LinkStatus::Succeeded);
    $this->internal('POST', '/internal/v1/payflow/payment-links/'.$link->id.'/sync', [])->assertOk()->assertJson(['recorded' => 1, 'deactivated' => true]);
    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Paid);
});

it('serves the dashboard, analytics and search with contract props', function (): void {
    $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('currency', 'USD')
        ->has('kpis', fn (Assert $kpis) => $kpis
            ->where('outstanding', ['amount' => ['minor' => 235000, 'currency' => 'USD'], 'count' => 1])
            ->has('overdue')
            ->has('collected_this_month')
            ->has('dso.trend', 6)
            ->has('at_risk'))
        ->has('aging', 5)
        ->where('aging.0.bucket', 'current')
        ->has('forecast.weeks', 8)
        ->has('attention')
        ->has('upcoming_reminders')
        ->has('late_payers')
        ->has('guard')
        ->has('insights')
        ->has('onboarding', 7)
        ->has('stats', 4)
        ->has('pf.nav_counts', 5)
        ->where('pf.payment_account.driver', 'fake')
        ->where('pf.entitlements.risk_scoring', true)
        ->has('pf.stepup')
        ->where('pf.guard.privileged', true));

    $this->get(route('analytics.index', ['months' => 3]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('analytics/Index')
        ->where('months', 3)
        ->has('monthly', 3)
        ->has('dso_trend', 3)
        ->has('risk_mix', 3)
        ->has('tone_effectiveness', 4)
        ->where('locked', []));

    $this->getJson(route('pf.search', ['q' => 'green']))->assertOk()
        ->assertJsonPath('clients.0.name', 'Greenline Fitness')
        ->assertJsonPath('invoices.0.number', 'INV-2026-0001');
});
