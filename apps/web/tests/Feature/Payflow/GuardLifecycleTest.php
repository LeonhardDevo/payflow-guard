<?php

declare(strict_types=1);

use App\Domain\Guard\Enums\PayoutStatus;
use App\Domain\Guard\Enums\TrustedConfirmationStatus;
use App\Domain\Guard\Enums\TrustedContactStatus;
use App\Domain\Guard\Enums\TrustedSubjectType;
use App\Domain\Guard\Models\GuardAlert;
use App\Domain\Guard\Models\PayoutApproval;
use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\ReceivingDetailsVersion;
use App\Domain\Guard\Models\TrustedConfirmation;
use App\Domain\Guard\Models\TrustedContact;
use App\Domain\Guard\Models\Vendor;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Guard\Receiving\ReceivingDetailsService;
use App\Domain\Guard\Trusted\TrustedConfirmations;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\OutboxMessage;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Payflow\Support\Guard;
use Tests\Feature\Payflow\Support\Payflow;

beforeEach(function (): void {
    $this->clock = Payflow::freeze('2026-10-22T10:00:00Z');
    $this->workspace = Guard::workspace(Workspace::factory()->create(['timezone' => 'Europe/Riga']));
    $this->anna = Guard::member($this->workspace, WorkspaceRole::Owner);
    $this->mark = Guard::member($this->workspace, WorkspaceRole::Finance);
    $this->dmitry = Guard::member($this->workspace, WorkspaceRole::Approver);
    $this->olga = Guard::member($this->workspace, WorkspaceRole::Admin);
    $this->vendor = Guard::vendor($this->workspace, $this->mark, [
        'name' => 'QA Crew', 'legal_name' => 'QA Crew OÜ', 'email' => 'invoices@qacrew.example', 'phone' => '+372 5555 0199', 'country' => 'EE', 'default_currency' => 'EUR',
    ]);
    $this->account = Guard::verifiedAccount($this->vendor, $this->mark, $this->dmitry, Guard::iban('QA Crew OÜ', 'EE222200221077345612'));
    Guard::backdateVerification($this->account, 400);
    DB::table('vendors')->where('id', $this->vendor->id)->update(['created_at' => now()->subDays(500), 'phone_changed_at' => null]);
    $this->vendor->refresh();
});

function lifecycleTravel(object $test, string $instant): void
{
    $test->clock->travelTo($instant);
    Carbon::setTestNow(CarbonImmutable::parse($instant));
    CarbonImmutable::setTestNow(CarbonImmutable::parse($instant));
}

function lifecycleChecklist(): array
{
    return ['called_known_number' => true, 'confirmed_holder_name' => true, 'confirmed_last4' => true, 'confirmed_request_origin' => true];
}

it('resubmits a held payout to the newly verified account with cooling-off, dual approval and web-only decisions', function (): void {
    $payout = Guard::payout($this->workspace, $this->mark, $this->vendor, 290000);

    expect($payout->status)->toBe(PayoutStatus::PendingApproval)
        ->and($payout->required_approvals)->toBe(1)
        ->and($payout->vendor_bank_account_id)->toBe($this->account->id);

    $this->actingAs($this->mark)->post(route('vendors.bank-changes.store', $this->vendor), [
        ...Guard::iban('QA Crew OÜ', 'EE537700771003819264'), 'source' => 'vendor_email', 'requester_contact' => 'invoices@qacrew.example',
    ])->assertRedirect();
    $change = VendorBankAccount::query()->where('vendor_id', $this->vendor->id)->where('version', 2)->sole();

    expect($change->risk_band->value)->toBe('medium')
        ->and(collect($change->risk_signals)->pluck('code')->all())->toBe(['request_via_email', 'pending_payout'])
        ->and($payout->refresh()->status)->toBe(PayoutStatus::OnHold);

    $this->actingAs($this->mark)->postJson(route('payouts.resubmit', $payout))->assertStatus(409)->assertJsonPath('code', 'hold_not_cleared');
    $this->actingAs($this->dmitry)->withSession(Guard::stepUp())->post(route('guard.bank-changes.verify', $change), [
        'method' => 'callback_known_phone', 'checklist' => lifecycleChecklist(), 'note' => 'Called +372 5555 0199 from the vendor card',
    ])->assertRedirect();

    expect($change->refresh()->cooling_off_until?->toIso8601String())->toBe(now()->addHours(24)->toIso8601String());

    $this->actingAs($this->mark)->post(route('payouts.resubmit', $payout))->assertRedirect();
    $payout->refresh();
    $codes = collect($payout->risk_signals)->pluck('code')->all();

    expect($payout->status)->toBe(PayoutStatus::PendingApproval)
        ->and($payout->approval_round)->toBe(2)
        ->and($payout->vendor_bank_account_id)->toBe($change->id)
        ->and($payout->bank_fingerprint)->toBe($change->fingerprint)
        ->and($codes)->toContain('cooling_off', 'bank_change_risk_medium', 'first_payout')
        ->and($codes)->not->toContain('account_recently_verified')
        ->and($payout->risk_band?->value)->toBe('high')
        ->and($payout->required_approvals)->toBe(2)
        ->and($payout->telegram_allowed)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'payflow.payout.resubmitted')->where('subject_id', $payout->id)->exists())->toBeTrue();

    $this->flushSession();
    $this->actingAs($this->dmitry)->postJson(route('payouts.approve', $payout))->assertStatus(423)->assertJsonPath('code', 'step_up_required');
    $this->actingAs($this->dmitry)->withSession(Guard::stepUp())->post(route('payouts.approve', $payout))->assertRedirect();
    $this->actingAs($this->olga)->withSession(Guard::stepUp())->post(route('payouts.approve', $payout))->assertRedirect();

    expect($payout->refresh()->status)->toBe(PayoutStatus::Approved)
        ->and(PayoutApproval::query()->where('payout_request_id', $payout->id)->where('approval_round', 2)->whereNull('invalidated_at')->count())->toBe(2);
});

it('validates the release date, requires step-up for non-low payouts and notifies the requester', function (): void {
    $payout = Guard::payout($this->workspace, $this->mark, $this->vendor, 290000);
    $this->actingAs($this->dmitry)->post(route('payouts.approve', $payout))->assertRedirect();
    $payout->refresh()->forceFill(['risk_band' => 'medium', 'risk_score' => 30])->save();
    OutboxMessage::query()->delete();

    $this->actingAs($this->anna)->postJson(route('payouts.release', $payout), ['paid_reference' => 'TRF-9', 'paid_on' => '2026-10-23'])
        ->assertStatus(422)->assertJsonValidationErrors('paid_on');
    $this->actingAs($this->anna)->withSession(Guard::stepUp())->postJson(route('payouts.release', $payout), ['paid_reference' => 'TRF-9', 'paid_on' => '2026-10-20'])
        ->assertStatus(422)->assertJsonPath('code', 'paid_on_before_approval');
    $this->flushSession();
    $this->actingAs($this->anna)->postJson(route('payouts.release', $payout), ['paid_reference' => 'TRF-9', 'paid_on' => '2026-10-22'])
        ->assertStatus(423)->assertJsonPath('code', 'step_up_required');
    $this->actingAs($this->dmitry)->withSession(Guard::stepUp())->postJson(route('payouts.release', $payout), ['paid_reference' => 'TRF-9', 'paid_on' => '2026-10-22'])
        ->assertForbidden();
    $this->actingAs($this->anna)->withSession(Guard::stepUp())->post(route('payouts.release', $payout), ['paid_reference' => 'TRF-9', 'paid_on' => '2026-10-22'])
        ->assertRedirect(route('payouts.show', $payout));

    $email = collect(Payflow::emails())->firstWhere('to', $this->mark->email);

    expect($payout->refresh()->status)->toBe(PayoutStatus::Released)
        ->and($payout->paid_reference)->toBe('TRF-9')
        ->and($email)->not->toBeNull()
        ->and($email['subject'])->toBe('Payout released')
        ->and($email['text'])->toContain('TRF-9')
        ->and(Payflow::emailPayloadErrors($email))->toBeNull();

    $this->actingAs($this->anna)->withSession(Guard::stepUp())->postJson(route('payouts.cancel', $payout), ['reason' => 'Paid twice by mistake'])
        ->assertStatus(409)->assertJsonPath('code', 'invalid_payout_transition');
});

it('moves an approved payout to on hold when its bank fingerprint no longer matches at release', function (): void {
    $payout = Guard::payout($this->workspace, $this->mark, $this->vendor, 290000);
    $this->actingAs($this->dmitry)->post(route('payouts.approve', $payout))->assertRedirect();
    DB::table('payout_requests')->where('id', $payout->id)->update(['bank_fingerprint' => str_repeat('0', 64)]);

    $this->actingAs($this->anna)->postJson(route('payouts.release', $payout), ['paid_reference' => 'TRF-1', 'paid_on' => '2026-10-22'])
        ->assertStatus(409)->assertJsonPath('code', 'details_changed');

    expect($payout->refresh()->status)->toBe(PayoutStatus::OnHold)
        ->and($payout->hold_reason?->value)->toBe('bank_details_changed')
        ->and($payout->approvals_count)->toBe(0)
        ->and(PayoutApproval::query()->where('payout_request_id', $payout->id)->whereNull('invalidated_at')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'payflow.payout.held')->where('subject_id', $payout->id)->exists())->toBeTrue();
});

it('refuses Telegram approvals once the workspace switches them off, even for payouts submitted earlier', function (): void {
    $telegram = Guard::telegram($this->dmitry, $this->workspace, 700000123);
    $payout = Guard::payout($this->workspace, $this->mark, $this->vendor, 290000);
    $fp8 = substr((string) $payout->bank_fingerprint, 0, 8);

    expect($payout->telegram_allowed)->toBeTrue();

    WorkspaceProvisioner::settingsFor($this->workspace)->forceFill(['allow_telegram_approvals' => false])->save();

    $this->internal('POST', '/internal/v1/payflow/bot/payouts/'.$payout->id.'/decision', [
        'telegram_user_id' => $telegram->telegram_user_id, 'decision' => 'approve', 'fp8' => $fp8, 'confirmed' => true,
    ])->assertOk()->assertJsonPath('ok', false)->assertJsonPath('code', 'web_only')->assertJsonPath('buttons.0.url', route('payouts.show', $payout->id));

    $view = $this->internal('GET', '/internal/v1/payflow/bot/views/payout?telegram_user_id='.$telegram->telegram_user_id.'&arg='.$payout->id)->assertOk();

    expect(collect($view->json('buttons'))->pluck('callback')->filter()->all())->toBe([])
        ->and($payout->refresh()->status)->toBe(PayoutStatus::PendingApproval);
});

it('isolates Guard records between workspaces', function (): void {
    $payout = Guard::payout($this->workspace, $this->mark, $this->vendor, 290000);
    $alert = GuardAlert::query()->where('dedupe_key', 'po:'.$payout->id.':1')->sole();
    $version = app(CurrentWorkspace::class)->runAs($this->workspace, fn (): ReceivingDetailsVersion => app(ReceivingDetailsService::class)->request($this->workspace, $this->anna, 'IBAN LV80BANK0000435195001, Northstar Demo Bank'));
    $other = Guard::workspace(Workspace::factory()->create());
    $intruder = Guard::member($other, WorkspaceRole::Owner);
    $session = Guard::stepUp();

    $this->actingAs($intruder)->get(route('vendors.show', $this->vendor))->assertNotFound();
    $this->actingAs($intruder)->get(route('payouts.show', $payout))->assertNotFound();
    $this->actingAs($intruder)->get(route('guard.bank-changes.show', $this->account))->assertNotFound();
    $this->actingAs($intruder)->withSession($session)->postJson(route('payouts.approve', $payout))->assertNotFound();
    $this->actingAs($intruder)->withSession($session)->postJson(route('payouts.cancel', $payout), ['reason' => 'Not ours at all'])->assertNotFound();
    $this->actingAs($intruder)->postJson(route('guard.alerts.acknowledge', $alert))->assertNotFound();
    $this->actingAs($intruder)->withSession($session)->postJson(route('vendors.bank-accounts.reveal', [$this->vendor, $this->account]))->assertNotFound();
    $this->actingAs($intruder)->withSession($session)->postJson(route('settings.receiving.confirm', $version))->assertNotFound();
    $this->actingAs($intruder)->withSession($session)->postJson(route('vendors.anonymise', $this->vendor), ['confirm_name' => 'QA Crew'])->assertNotFound();
    $this->actingAs($intruder)->get(route('payouts.index', ['tab' => 'all']))
        ->assertInertia(fn (Assert $page) => $page->component('payouts/Index')->where('payouts.data', [])->where('counts.all', 0));
    $this->actingAs($intruder)->get(route('vendors.index'))
        ->assertInertia(fn (Assert $page) => $page->component('vendors/Index')->where('vendors.data', [])->where('counts.all', 0));

    expect($payout->refresh()->status)->toBe(PayoutStatus::PendingApproval)
        ->and($alert->refresh()->status->value)->toBe('open')
        ->and($version->refresh()->confirmed_by)->toBeNull();
});

it('archives vendors only without open payouts and keeps archived vendors out of new payouts', function (): void {
    $payout = Guard::payout($this->workspace, $this->mark, $this->vendor, 290000);

    $this->actingAs($this->mark)->deleteJson(route('vendors.destroy', $this->vendor))->assertStatus(409)->assertJsonPath('code', 'has_open_items');
    $this->actingAs($this->mark)->post(route('payouts.cancel', $payout), ['reason' => 'Ordered by mistake'])->assertRedirect();
    $this->actingAs($this->mark)->delete(route('vendors.destroy', $this->vendor))->assertRedirect(route('vendors.index'));

    $archived = Vendor::withTrashed()->findOrFail($this->vendor->id);

    expect($archived->status->value)->toBe('archived')
        ->and($archived->trashed())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'payflow.vendor.archived')->exists())->toBeTrue()
        ->and(GuardAlert::query()->where('dedupe_key', 'po:'.$payout->id.':1')->value('status')?->value)->toBe('resolved');

    $this->actingAs($this->mark)->postJson(route('payouts.store'), [
        'vendor_id' => $this->vendor->id, 'amount' => '100.00', 'currency' => 'EUR', 'description' => 'Late invoice', 'submit' => false,
    ])->assertStatus(422);
});

it('runs the hourly Guard job: stale approvals, confirmation reminders and expiry, receiving-details activation', function (): void {
    $solo = Guard::workspace(Workspace::factory()->create(['timezone' => 'Europe/Riga']));
    $owner = Guard::member($solo, WorkspaceRole::Owner);
    $contact = TrustedContact::query()->create([
        'workspace_id' => $solo->id, 'name' => 'Lena Accountant', 'email' => 'lena@accounting.example', 'locale' => 'ru',
        'status' => TrustedContactStatus::Active, 'verified_at' => now()->subDays(10), 'active_from' => now()->subDays(7), 'created_by' => $owner->id,
    ]);
    $confirmation = app(CurrentWorkspace::class)->runAs($solo, fn (): TrustedConfirmation => app(TrustedConfirmations::class)->create(
        $contact, TrustedSubjectType::PayoutRequest, 999, ['number' => 'PO-2026-0999', 'amount_minor' => 90000, 'currency' => 'EUR', 'vendor' => 'Solo Vendor'], $owner,
    ));
    $payout = Guard::payout($this->workspace, $this->mark, $this->vendor, 290000);
    $version = app(CurrentWorkspace::class)->runAs($this->workspace, fn (): ReceivingDetailsVersion => app(ReceivingDetailsService::class)->request($this->workspace, $this->anna, 'IBAN LV80BANK0000435195001, Northstar Demo Bank'));
    app(CurrentWorkspace::class)->runAs($this->workspace, fn () => app(ReceivingDetailsService::class)->confirm($version->refresh(), $this->dmitry));
    $run = fn (string $now) => $this->internal('POST', '/internal/v1/payflow/payouts/escalations/run', ['now' => $now])->assertOk();

    config(['payflow.allow_clock_override' => true]);
    lifecycleTravel($this, '2026-10-22T23:00:00Z');
    OutboxMessage::query()->delete();
    $run('2026-10-22T23:00:00Z')->assertExactJson(['escalated' => 0, 'confirmations_reminded' => 1, 'confirmations_expired' => 0, 'receiving_details_activated' => 0]);

    $reminder = collect(Payflow::emails())->firstWhere('to', 'lena@accounting.example');

    expect($reminder)->not->toBeNull()->and(Payflow::emailPayloadErrors($reminder))->toBeNull();

    lifecycleTravel($this, '2026-10-23T11:00:00Z');
    $run('2026-10-23T11:00:00Z')->assertExactJson(['escalated' => 1, 'confirmations_reminded' => 0, 'confirmations_expired' => 1, 'receiving_details_activated' => 1]);

    expect($confirmation->refresh()->status)->toBe(TrustedConfirmationStatus::Expired)
        ->and($payout->refresh()->last_escalated_at?->toIso8601String())->toBe('2026-10-23T11:00:00+00:00')
        ->and(GuardAlert::query()->where('dedupe_key', 'po-stale:'.$payout->id)->value('status')?->value)->toBe('open')
        ->and($version->refresh()->status->value)->toBe('active')
        ->and(WorkspaceProvisioner::settingsFor($this->workspace)->active_receiving_details_id)->toBe($version->id)
        ->and(AuditLog::query()->where('action', 'payflow.receiving_details.activated')->exists())->toBeTrue();

    lifecycleTravel($this, '2026-10-23T12:00:00Z');
    $run('2026-10-23T12:00:00Z')->assertExactJson(['escalated' => 0, 'confirmations_reminded' => 0, 'confirmations_expired' => 0, 'receiving_details_activated' => 0]);

    lifecycleTravel($this, '2026-10-24T11:30:00Z');
    $run('2026-10-24T11:30:00Z')->assertJsonPath('escalated', 1);
});

it('serves Guard data to the assistant only for members, with resolved vendors and without holder names', function (): void {
    $this->actingAs($this->mark)->post(route('vendors.bank-changes.store', $this->vendor), [
        ...Guard::iban('QA Crew Payments UAB', 'EE537700771003819264'), 'source' => 'vendor_email', 'requester_contact' => 'billing@qacrevv.example',
        'change_request_text' => 'Urgent: new account, call +372 5000 1234',
    ])->assertRedirect();
    $outsider = Guard::member(Guard::workspace(Workspace::factory()->create()), WorkspaceRole::Owner);
    $query = fn (string $path, int $userId, string $extra = '') => $this->internal('GET', '/internal/v1/payflow/ai/'.$path.'?workspace_id='.$this->workspace->id.'&user_id='.$userId.'&locale=en'.$extra, null, 'ai');

    $query('vendors/bank-changes', $outsider->id)->assertForbidden();
    $query('payouts/pending', $outsider->id)->assertForbidden();
    $this->internal('GET', '/internal/v1/payflow/ai/payouts/pending?workspace_id='.$this->workspace->id.'&user_id='.$this->mark->id.'&locale=en', null, 'worker')->assertForbidden();

    $response = $query('vendors/bank-changes', $this->dmitry->id, '&vendor_query=qa%20krew')->assertOk();
    $item = $response->json('data.items.0');
    $raw = (string) $response->getContent();

    expect($response->json('resolved.kind'))->toBe('vendor')
        ->and($response->json('resolved.id'))->toBe($this->vendor->id)
        ->and($response->json('resolved.score'))->toBeLessThan(1)
        ->and($item['status'])->toBe('pending_verification')
        ->and($item['after'])->toBe(['bank_country' => 'EE', 'currency' => 'EUR', 'last4' => '9264', 'holder_matches_vendor' => false, 'holder_similarity' => 'mismatch'])
        ->and($item['before']['last4'])->toBe('5612')
        ->and($item['before']['holder_similarity'])->toBe('match')
        ->and($raw)->not->toContain('QA Crew Payments UAB')
        ->and($raw)->not->toContain('EE537700771003819264')
        ->and($raw)->not->toContain('Urgent')
        ->and($response->json('as_of'))->not->toBeNull();

    $query('vendors/bank-changes', $this->dmitry->id, '&vendor_query=Nobody%20Known')->assertOk()->assertJsonPath('error', 'not_found');
});

it('sends a reminder from Telegram only after confirmation and asks before overriding a recent one', function (): void {
    $anna = Guard::telegram($this->anna, $this->workspace, 700000501);
    $dmitry = Guard::telegram($this->dmitry, $this->workspace, 700000502);
    $client = Payflow::client($this->workspace, ['name' => 'Helios Media Group', 'email' => 'ap@helios.example']);
    $invoice = Payflow::invoice($this->workspace, $this->anna, $client, '2026-09-01', '2026-09-30');
    $remind = fn (int $telegramId, array $body) => $this->internal('POST', '/internal/v1/payflow/bot/invoices/remind', ['telegram_user_id' => $telegramId, ...$body]);

    $remind($anna->telegram_user_id, ['invoice' => strtolower((string) $invoice->number), 'confirmed' => false])->assertOk()
        ->assertJsonPath('code', 'confirm_required')
        ->assertJsonPath('buttons.0.callback', 'pf.rmc:'.$invoice->id)
        ->assertJsonPath('buttons.1.callback', 'pf.x');

    expect(DB::table('reminders')->where('invoice_id', $invoice->id)->count())->toBe(0);

    $remind($anna->telegram_user_id, ['invoice_id' => $invoice->id, 'confirmed' => true])->assertOk()->assertJsonPath('ok', true);
    $remind($anna->telegram_user_id, ['invoice_id' => $invoice->id, 'confirmed' => true])->assertOk()
        ->assertJsonPath('ok', false)->assertJsonPath('code', 'recent_reminder')->assertJsonPath('buttons.0.callback', 'pf.rmf:'.$invoice->id);
    $remind($anna->telegram_user_id, ['invoice_id' => $invoice->id, 'confirmed' => true, 'force' => true])->assertOk()->assertJsonPath('ok', true);
    $remind($dmitry->telegram_user_id, ['invoice_id' => $invoice->id, 'confirmed' => true])->assertOk()->assertJsonPath('code', 'forbidden');
    $remind($anna->telegram_user_id, ['invoice' => 'NS-0000-9999', 'confirmed' => false])->assertOk()->assertJsonPath('code', 'not_found');

    $outsider = Guard::member(Guard::workspace(Workspace::factory()->create()), WorkspaceRole::Owner);
    $foreign = Guard::telegram($outsider, Workspace::query()->findOrFail($outsider->current_workspace_id), 700000503);
    $remind($foreign->telegram_user_id, ['invoice_id' => $invoice->id, 'confirmed' => true])->assertOk()->assertJsonPath('code', 'forbidden');

    expect(DB::table('reminders')->where('invoice_id', $invoice->id)->where('kind', 'manual')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'payflow.reminder.sent_manually')->count())->toBe(2);
});

it('purges Guard evidence per retention key and keeps financial records', function (): void {
    $payout = Guard::payout($this->workspace, $this->mark, $this->vendor, 290000);
    $this->actingAs($this->dmitry)->post(route('payouts.approve', $payout))->assertRedirect();
    $approval = PayoutApproval::query()->where('payout_request_id', $payout->id)->sole();
    DB::table('payout_approvals')->where('id', $approval->id)->update(['created_at' => now()->subDays(366), 'user_agent' => 'Mozilla/5.0']);
    $old = Guard::verifiedAccount(Guard::vendor($this->workspace, $this->mark, ['name' => 'Old Vendor']), $this->mark, $this->dmitry, Guard::iban('Old Vendor'));
    DB::table('vendor_bank_accounts')->where('id', $old->id)->update(['status' => 'superseded', 'superseded_at' => now()->subDays(1100)]);
    $old->forceFill(['change_request_text' => 'Please use the new account', 'requester_contact' => 'old@vendor.example'])->save();
    DB::table('vendor_bank_accounts')->where('id', $old->id)->update(['status' => 'superseded', 'superseded_at' => now()->subDays(1100)]);
    $contact = TrustedContact::query()->create([
        'workspace_id' => $this->workspace->id, 'name' => 'Lena', 'email' => 'lena@accounting.example', 'locale' => 'en',
        'status' => TrustedContactStatus::Active, 'verified_at' => now(), 'active_from' => now(), 'created_by' => $this->anna->id,
    ]);
    $confirmation = app(CurrentWorkspace::class)->runAs($this->workspace, fn (): TrustedConfirmation => app(TrustedConfirmations::class)->create(
        $contact, TrustedSubjectType::PayoutRequest, $payout->id, ['number' => $payout->number, 'vendor' => 'QA Crew', 'amount_minor' => 290000, 'currency' => 'EUR'], $this->anna,
    ));
    DB::table('trusted_confirmations')->where('id', $confirmation->id)->update(['status' => 'expired', 'created_at' => now()->subDays(400)]);
    $resolved = GuardAlert::query()->where('dedupe_key', 'po:'.$payout->id.':1')->sole();
    DB::table('guard_alerts')->where('id', $resolved->id)->update(['status' => 'resolved', 'resolved_at' => now()->subDays(731)]);

    $purged = $this->internal('POST', '/internal/v1/payflow/maintenance/purge', [])->assertOk()->json('purged');

    expect($purged['approval_network'])->toBe(1)
        ->and($purged['bank_change_evidence'])->toBe(1)
        ->and($purged['trusted_confirmation_tokens'])->toBe(1)
        ->and($purged['alerts'])->toBe(1)
        ->and(DB::table('payout_approvals')->where('id', $approval->id)->value('user_agent'))->toBeNull()
        ->and($old->refresh()->change_request_text)->toBeNull()
        ->and($old->requester_contact)->toBeNull()
        ->and($old->last4)->toBe('0519')
        ->and($confirmation->refresh()->snapshot)->toEqualCanonicalizing(['amount_minor' => 290000, 'currency' => 'EUR'])
        ->and(GuardAlert::query()->whereKey($resolved->id)->exists())->toBeFalse()
        ->and(PayoutRequest::query()->whereKey($payout->id)->exists())->toBeTrue()
        ->and(PayoutApproval::query()->whereKey($approval->id)->exists())->toBeTrue();

    expect($this->internal('POST', '/internal/v1/payflow/maintenance/purge', [])->json('purged'))
        ->toMatchArray(['approval_network' => 0, 'bank_change_evidence' => 0, 'trusted_confirmation_tokens' => 0, 'alerts' => 0]);
});
