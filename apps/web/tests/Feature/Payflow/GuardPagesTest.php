<?php

declare(strict_types=1);

use App\Domain\Guard\Models\GuardAlert;
use App\Domain\Notifications\Models\MemberNotificationSetting;
use App\Domain\Notifications\NotificationRenderer;
use App\Domain\Notifications\Routing\GuardNotifications;
use App\Enums\WorkspaceRole;
use App\Locale\WithLocale;
use App\Models\OutboxMessage;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Payflow\Support\Guard;
use Tests\Feature\Payflow\Support\Payflow;

beforeEach(function (): void {
    Payflow::freeze('2026-10-22T10:00:00Z');
    $this->workspace = Guard::workspace(Workspace::factory()->create(['timezone' => 'Europe/Riga']));
    $this->anna = Guard::member($this->workspace, WorkspaceRole::Owner);
    $this->mark = Guard::member($this->workspace, WorkspaceRole::Finance);
    $this->dmitry = Guard::member($this->workspace, WorkspaceRole::Approver);
    $this->vendor = Guard::vendor($this->workspace, $this->mark, ['name' => 'Copywrite Lab', 'legal_name' => 'Copywrite Lab LLC', 'country' => 'US', 'default_currency' => 'USD']);
    Guard::verifiedAccount($this->vendor, $this->mark, $this->dmitry, [
        'scheme' => 'us_ach', 'account_holder_name' => 'Copywrite Lab LLC', 'routing_number' => '123456780', 'account_number' => '7700112870', 'bank_country' => 'US', 'currency' => 'USD',
    ]);
    $this->payout = Guard::payout($this->workspace, $this->mark, $this->vendor->refresh(), 1250000, 'USD');
});

it('renders the Guard overview with KPIs, decisions and rules', function (): void {
    $this->actingAs($this->dmitry)->get(route('guard.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('guard/Index')
        ->where('kpis.awaiting_me', 1)
        ->where('kpis.pending_approvals.count', 1)
        ->where('kpis.pending_approvals.amounts', [['minor' => 1250000, 'currency' => 'USD']])
        ->where('kpis.bank_changes_pending', 0)
        ->has('needs_decision', 1, fn (Assert $item) => $item
            ->where('number', $this->payout->number)
            ->where('awaiting_me', true)
            ->where('required_approvals', 2)
            ->etc())
        ->has('alerts.data')
        ->has('alerts.meta')
        ->where('alert_filters', ['severity' => null, 'status' => 'active'])
        ->where('rules.eligible_approvers', 2)
        ->where('rules.solo_mode', false)
        ->where('rules.trusted_contact', null)
        ->where('me.privileged', true)
        ->where('me.missing_second_factor', false)
        ->where('pf.nav_counts.my_approvals', 1)
        ->has('pf.guard.eligible_from'));
});

it('renders payout lists, detail and form props in the contract shape', function (): void {
    $this->actingAs($this->dmitry)->get(route('payouts.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('payouts/Index')
        ->where('tab', 'awaiting_me')
        ->where('counts.awaiting_me', 1)
        ->where('counts.all', 1)
        ->has('payouts.data', 1)
        ->has('vendors', 1));

    $this->actingAs($this->dmitry)->get(route('payouts.show', $this->payout))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('payouts/Show')
        ->where('payout.number', $this->payout->number)
        ->where('payout.bank_account.display', '•••• 2870 (ACH)')
        ->where('payout.current_account_matches', true)
        ->where('payout.approval_round', 1)
        ->has('payout.eligible_approvers', 2)
        ->has('payout.checks', 9)
        ->where('payout.checks.6.code', 'approvals_complete')
        ->where('payout.checks.6.ok', false)
        ->where('payout.trusted_confirmation', null)
        ->has('payout.timeline', 2)
        ->where('can.approve', true)
        ->where('can.release', false)
        ->where('cannot_approve_reason', null)
        ->where('cannot_release_reason', 'not_approved')
        ->has('disclaimer_url'));

    $this->actingAs($this->mark)->get(route('payouts.show', $this->payout))->assertInertia(fn (Assert $page) => $page
        ->where('can.approve', false)
        ->where('cannot_approve_reason', 'no_permission')
        ->where('can.cancel', true));

    $this->actingAs($this->mark)->get(route('payouts.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('payouts/Create')
        ->where('vendors.0.currency', 'USD')
        ->where('vendors.0.account_display', '•••• 2870 (ACH)')
        ->where('approvers_available', ['eligible' => 2, 'trusted_contact' => false])
        ->has('thresholds', 2)
        ->has('limits.payouts_per_month'));

    $this->actingAs($this->mark)->get(route('vendors.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('vendors/Create')->where('vendor', null)->has('categories', 5)->has('currencies')->has('limits.vendors'));
    $this->actingAs($this->mark)->get(route('vendors.bank-changes.create', $this->vendor))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('vendors/BankChangeCreate')->where('current.last4', '2870')->has('sources', 5));
    $this->actingAs($this->dmitry)->get(route('payouts.create'))->assertForbidden();
});

it('sends approval requests only to eligible approvers other than the requester, in their locale', function (): void {
    $this->dmitry->forceFill(['locale' => 'ru'])->save();
    $this->payout->forceFill(['status' => 'canceled'])->save();
    OutboxMessage::query()->delete();
    $payout = Guard::payout($this->workspace, $this->mark, $this->vendor, 20000, 'USD');
    $emails = collect(Payflow::emails())->where('reference.type', 'notification_payout');

    expect($emails->pluck('to')->sort()->values()->all())->toBe(collect([$this->anna->email, $this->dmitry->email])->sort()->values()->all())
        ->and($emails->firstWhere('to', $this->dmitry->email)['subject'])->toBe('Выплата ждёт вашего согласования')
        ->and($emails->firstWhere('to', $this->anna->email)['text'])->toContain('Risk scores are indicators, not a guarantee.')
        ->and($emails->firstWhere('to', $this->anna->email)['text'])->toContain($payout->number);

    Guard::telegram($this->anna, $this->workspace, 700000001);
    OutboxMessage::query()->delete();
    $next = Guard::payout($this->workspace, $this->mark, $this->vendor, 30000, 'USD');
    $telegram = OutboxMessage::query()->where('topic', 'telegram.notify')->where('payload->user_id', $this->anna->id)->sole()->payload;

    expect($telegram['text_html'])->toContain('<b>Payout awaiting your approval</b>')
        ->and(collect($telegram['buttons'])->pluck('callback')->filter()->values()->all())->toBe(['pf.pa:'.$next->id.':'.substr((string) $next->bank_fingerprint, 0, 8), 'pf.pr:'.$next->id.':'.substr((string) $next->bank_fingerprint, 0, 8)]);
});

it('forces critical Guard notifications through quiet hours and escapes user data', function (): void {
    MemberNotificationSetting::query()->create([
        'workspace_id' => $this->workspace->id, 'user_id' => $this->anna->id, 'telegram_events' => [], 'email_events' => [],
        'digest_channel' => 'none', 'quiet_hours_start' => '00:00', 'quiet_hours_end' => '23:59',
    ]);
    OutboxMessage::query()->delete();

    app(CurrentWorkspace::class)->runAs($this->workspace, fn () => app(GuardNotifications::class)->guardRoleGranted(
        $this->workspace, $this->anna, $this->mark, WorkspaceRole::Approver, now()->addHours(72), null,
    ));
    $anna = OutboxMessage::query()->where('topic', 'email.send')->where('payload->to', $this->anna->email)->sole();

    expect($anna->available_at->utc()->format('Y-m-d H:i'))->not->toBe('2026-10-22 20:59')
        ->and($anna->payload['subject'])->toBe('New person can approve payments');

    $rendered = WithLocale::run('ru', fn (): array => NotificationRenderer::render('trusted_contact_changed', ['user' => '<script>x</script>', 'status' => 'активно']));

    expect($rendered['title'])->toBe('Изменено доверенное лицо')
        ->and($rendered['body'])->toContain('&lt;script&gt;')
        ->and($rendered['body'])->not->toMatch('/\p{Extended_Pictographic}/u');
});

it('acknowledges and resolves alerts with permission checks', function (): void {
    $alert = GuardAlert::query()->where('dedupe_key', 'po:'.$this->payout->id.':1')->sole();
    $member = Guard::member($this->workspace, WorkspaceRole::Member);

    $this->actingAs($member)->post(route('guard.alerts.acknowledge', $alert))->assertForbidden();
    $this->actingAs($this->dmitry)->post(route('guard.alerts.acknowledge', $alert))->assertRedirect();
    expect($alert->refresh()->status->value)->toBe('acknowledged');

    $this->actingAs($this->dmitry)->post(route('guard.alerts.resolve', $alert), ['note' => 'Handled by phone'])->assertRedirect();
    expect($alert->refresh()->status->value)->toBe('resolved')->and($alert->resolution_note)->toBe('Handled by phone');
});
