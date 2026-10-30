<?php

declare(strict_types=1);

use App\Domain\Guard\Enums\TrustedContactStatus;
use App\Domain\Guard\Models\GuardAlert;
use App\Domain\Guard\Models\TrustedConfirmation;
use App\Domain\Guard\Models\TrustedContact;
use App\Domain\Guard\Models\Vendor;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Guard\Payouts\PayoutService;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Payflow\Support\Guard;
use Tests\Feature\Payflow\Support\Payflow;

beforeEach(function (): void {
    Payflow::freeze('2026-10-22T10:00:00Z');
    $this->workspace = Guard::workspace(Workspace::factory()->create(['timezone' => 'Europe/Riga']));
    $this->anna = Guard::member($this->workspace, WorkspaceRole::Owner);
    $this->mark = Guard::member($this->workspace, WorkspaceRole::Finance);
    $this->dmitry = Guard::member($this->workspace, WorkspaceRole::Approver);
    $this->olga = Guard::member($this->workspace, WorkspaceRole::Admin);
    $this->vendor = Guard::vendor($this->workspace, $this->mark, [
        'name' => 'PixelForge Studio', 'legal_name' => 'PixelForge Studio sp. z o.o.', 'email' => 'billing@pixelforge.example', 'country' => 'PL', 'default_currency' => 'EUR',
    ]);
    $this->current = Guard::verifiedAccount($this->vendor, $this->mark, $this->dmitry, Guard::iban('PixelForge Studio sp. z o.o.', 'PL61109010140000071219812874'));
    Guard::backdateVerification($this->current, 200);
});

function requestPixelChange(object $test, array $overrides = []): VendorBankAccount
{
    $test->actingAs($test->mark)->post(route('vendors.bank-changes.store', $test->vendor), [
        ...Guard::iban('PF Studio Payments UAB'),
        'source' => 'vendor_email',
        'requester_contact' => 'billing@pixelf0rge.example',
        'change_request_text' => 'Hello, due to an audit please pay urgently today. Do not call, we are travelling. Mob. +370 600 41 977',
        ...$overrides,
    ])->assertRedirect();

    return VendorBankAccount::query()->where('vendor_id', $test->vendor->id)->where('status', 'pending_verification')->sole();
}

it('records a bank change with risk signals, alerts and an audit entry without account numbers', function (): void {
    $change = requestPixelChange($this);
    $audit = AuditLog::query()->where('action', 'payflow.bank_account.requested')->latest('id')->firstOrFail();

    expect($change->risk_band->value)->toBe('high')
        ->and(collect($change->risk_signals)->pluck('code')->all())->toContain('lookalike_domain', 'holder_name_mismatch', 'country_changed', 'urgency_language', 'secrecy_language', 'new_contact_in_request')
        ->and($change->requester_email_domain)->toBe('pixelf0rge.example')
        ->and($change->last4)->toBe('0519')
        ->and(DB::table('vendor_bank_accounts')->where('id', $change->id)->value('iban'))->not->toContain('LT58')
        ->and(GuardAlert::query()->where('dedupe_key', 'bca:'.$change->id)->exists())->toBeTrue()
        ->and(GuardAlert::query()->where('dedupe_key', 'bca-high:'.$change->id)->value('severity')?->value)->toBe('critical')
        ->and(json_encode($audit->metadata))->not->toContain('LT583250067741200519')
        ->and($audit->metadata['last4'])->toBe('0519');

    $this->actingAs($this->mark)->postJson(route('vendors.bank-changes.store', $this->vendor), [
        ...Guard::iban('Another Holder', 'EE537700771003819264'), 'source' => 'manual_entry',
    ])->assertStatus(409)->assertJsonPath('code', 'bank_change_pending');

    $this->actingAs($this->mark)->postJson(route('vendors.bank-changes.store', $this->vendor), [
        ...Guard::iban('PF', 'LT00 1234'), 'source' => 'vendor_email',
    ])->assertStatus(422)->assertJsonValidationErrors(['iban', 'requester_contact']);
});

it('renders the review page once-audited with masked data and the known phone', function (): void {
    $change = requestPixelChange($this);

    $this->actingAs($this->dmitry)->get(route('guard.bank-changes.show', $change))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('guard/BankChangeReview')
        ->where('change.id', $change->id)
        ->where('change.display', 'LT58 •••• •••• •••• 0519')
        ->where('change.holder_name', 'PF Studio Payments UAB')
        ->where('change.requester_contact_hint', 'pixelf0rge.example')
        ->where('change.risk_band', 'high')
        ->where('previous.id', $this->current->id)
        ->where('callback.known_phone', '+48 22 555 01 47')
        ->where('requirements.step_up_required', true)
        ->where('requirements.note_required', true)
        ->where('requirements.allowed_methods', ['callback_known_phone', 'in_person', 'signed_letter', 'video_call'])
        ->where('can.verify', true)
        ->where('can.reveal', true)
        ->where('cannot_verify_reason', null)
        ->has('diff', 7)
        ->has('vendor')
        ->has('affected_payouts')
        ->has('disclaimer_url')
        ->missing('change.iban'));
    $this->actingAs($this->dmitry)->get(route('guard.bank-changes.show', $change))->assertOk();
    $this->actingAs($this->mark)->get(route('guard.bank-changes.show', $change))
        ->assertInertia(fn (Assert $page) => $page->where('can.verify', false)->where('cannot_verify_reason', 'is_requester')->where('can.withdraw', true));

    expect(AuditLog::query()->where('action', 'payflow.bank_account.viewed')->count())->toBe(2);
});

it('lets only an eligible other person verify, with step-up, method, note and cooling-off', function (): void {
    $change = requestPixelChange($this);
    $checklist = ['called_known_number' => true, 'confirmed_holder_name' => true, 'confirmed_last4' => true, 'confirmed_request_origin' => true];

    $this->actingAs($this->mark)->postJson(route('guard.bank-changes.verify', $change), ['method' => 'callback_known_phone', 'checklist' => $checklist])->assertForbidden();
    $this->actingAs($this->dmitry)->postJson(route('guard.bank-changes.verify', $change), ['method' => 'callback_known_phone', 'checklist' => $checklist, 'note' => 'Called the known number'])
        ->assertStatus(423);
    $this->actingAs($this->dmitry)->withSession(Guard::stepUp())->postJson(route('guard.bank-changes.verify', $change), ['method' => 'vendor_portal_2fa', 'checklist' => $checklist, 'note' => 'Portal confirmed'])
        ->assertStatus(422)->assertJsonPath('code', 'method_not_allowed');
    $this->actingAs($this->dmitry)->withSession(Guard::stepUp())->postJson(route('guard.bank-changes.verify', $change), ['method' => 'in_person', 'checklist' => $checklist, 'note' => 'short'])
        ->assertStatus(422)->assertJsonPath('code', 'note_required');
    $this->actingAs($this->dmitry)->withSession(Guard::stepUp())->postJson(route('guard.bank-changes.verify', $change), ['method' => 'in_person', 'checklist' => [...$checklist, 'confirmed_last4' => false], 'note' => 'Met the director in person'])
        ->assertStatus(422);
    $this->actingAs($this->dmitry)->withSession(Guard::stepUp())->post(route('guard.bank-changes.verify', $change), ['method' => 'callback_known_phone', 'checklist' => $checklist, 'note' => 'Called +48 22 555 01 47, confirmed with the director'])
        ->assertRedirect(route('guard.bank-changes.show', $change));

    $change->refresh();

    expect($change->status->value)->toBe('verified')
        ->and($change->verified_by_user_id)->toBe($this->dmitry->id)
        ->and($change->cooling_off_until?->toIso8601String())->toBe(now()->addHours(48)->toIso8601String())
        ->and($this->current->refresh()->status->value)->toBe('superseded')
        ->and($this->vendor->refresh()->current_bank_account_id)->toBe($change->id)
        ->and(GuardAlert::query()->where('dedupe_key', 'bca-high:'.$change->id)->value('status')?->value)->toBe('resolved')
        ->and(AuditLog::query()->where('action', 'payflow.bank_account.verified')->exists())->toBeTrue();

    $payout = Guard::payout($this->workspace, $this->mark, $this->vendor->refresh(), 50000);
    expect(collect($payout->risk_signals)->pluck('code')->all())->toContain('cooling_off', 'bank_change_risk_high')
        ->and($payout->required_approvals)->toBe(2)
        ->and($payout->telegram_allowed)->toBeFalse();

    expect(fn () => DB::table('vendor_bank_accounts')->where('id', $change->id)->update(['verified_by_user_id' => $this->mark->id]))->toThrow(QueryException::class);
});

it('lets the requester withdraw and the verifier reject, restoring held payouts', function (): void {
    $payout = Guard::payout($this->workspace, $this->mark, $this->vendor, 50000);
    $change = requestPixelChange($this);
    expect($payout->refresh()->status->value)->toBe('on_hold');

    $this->actingAs($this->mark)->post(route('guard.bank-changes.reject', $change), ['reason' => 'Sent by mistake'])->assertRedirect();

    expect($change->refresh()->status->value)->toBe('rejected')
        ->and($change->rejected_by_user_id)->toBe($this->mark->id)
        ->and($payout->refresh()->status->value)->toBe('pending_approval')
        ->and($payout->approval_round)->toBe(2);

    $again = requestPixelChange($this);

    expect(collect($again->risk_signals)->pluck('code')->all())->toContain('reused_rejected_account', 'recent_change');
});

it('reveals full bank details only after step-up and audits it', function (): void {
    $route = route('vendors.bank-accounts.reveal', [$this->vendor, $this->current]);

    $this->actingAs($this->anna)->postJson($route)->assertStatus(423);
    $this->actingAs($this->anna)->withSession(Guard::stepUp())->postJson($route)
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJson(['iban' => 'PL61109010140000071219812874', 'expires_in' => 60]);
    $this->actingAs($this->olga)->withSession(Guard::stepUp())->postJson($route)->assertForbidden();

    expect(AuditLog::query()->where('action', 'payflow.bank_account.revealed')->count())->toBe(1);
});

it('runs the solo verification with a confirmed trusted contact and a long cooling-off', function (): void {
    $solo = Guard::workspace(Workspace::factory()->create());
    $owner = Guard::member($solo, WorkspaceRole::Owner);
    TrustedContact::query()->create([
        'workspace_id' => $solo->id, 'name' => 'Pat Reviewer', 'email' => 'trusted@e2e.example', 'locale' => 'en',
        'status' => TrustedContactStatus::Active, 'verified_at' => now()->subDays(10), 'active_from' => now()->subDays(7), 'created_by' => $owner->id,
    ]);
    $vendor = Guard::vendor($solo, $owner, ['name' => 'Solo Vendor', 'email' => 'ap@solo.example', 'country' => 'LT']);
    Guard::existingAccount($vendor, $owner, Guard::iban('Solo Vendor', 'EE537700771003819264'));
    $this->actingAs($owner)->post(route('vendors.bank-changes.store', $vendor), [...Guard::iban('Solo Vendor'), 'source' => 'manual_entry'])->assertRedirect();
    $change = VendorBankAccount::query()->where('vendor_id', $vendor->id)->where('status', 'pending_verification')->sole();
    $checklist = ['called_known_number' => true, 'confirmed_holder_name' => true, 'confirmed_last4' => true, 'confirmed_request_origin' => true];
    $form = ['method' => 'callback_known_phone', 'checklist' => $checklist, 'note' => 'Called the vendor on the number from the signed contract.', 'solo' => true];

    $this->actingAs($owner)->withSession(Guard::stepUp())->postJson(route('guard.bank-changes.verify', $change), $form)->assertStatus(409)->assertJsonPath('code', 'solo_confirmation_missing');
    $this->actingAs($owner)->post(route('guard.bank-changes.solo-confirmation', $change))->assertRedirect();
    $email = collect(Payflow::emails())->firstWhere('reference.type', 'trusted_confirmation');
    preg_match('#/tc/([A-Za-z0-9]{48})#', (string) $email['text'], $match);
    $this->post(route('trusted.decide', $match[1]), ['decision' => 'confirm'])->assertRedirect();

    $this->actingAs($owner)->withSession(Guard::stepUp())->postJson(route('guard.bank-changes.verify', $change), [...$form, 'note' => 'too short'])->assertStatus(422);
    $this->actingAs($owner)->withSession(Guard::stepUp())->post(route('guard.bank-changes.verify', $change), $form)->assertRedirect();

    $change->refresh();

    expect($change->status->value)->toBe('verified')
        ->and($change->verification_mode->value)->toBe('solo')
        ->and($change->verified_by_user_id)->toBe($owner->id)
        ->and($change->trusted_confirmation_id)->toBe(TrustedConfirmation::query()->sole()->id)
        ->and($change->cooling_off_until?->toIso8601String())->toBe(now()->addHours(72)->toIso8601String())
        ->and(AuditLog::query()->where('action', 'payflow.bank_account.verified_solo')->exists())->toBeTrue();
});

it('audits vendor phone changes and anonymises vendors without open payouts', function (): void {
    $this->vendor->forceFill(['phone' => '+48 22 555 01 47', 'phone_verified_at' => now()->subDays(5)])->save();
    $this->actingAs($this->mark)->put(route('vendors.update', $this->vendor), [
        'name' => 'PixelForge Studio', 'category' => 'contractor', 'default_currency' => 'EUR', 'phone' => '+370 600 41 977', 'email' => 'billing@pixelforge.example',
    ])->assertRedirect();

    expect($this->vendor->refresh()->phone_verified_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'payflow.vendor.phone_changed')->value('metadata'))->toEqual(['from_last3' => '147', 'to_last3' => '977'])
        ->and(GuardAlert::query()->where('type', 'vendor_phone_changed')->exists())->toBeTrue();

    $payout = Guard::payout($this->workspace, $this->mark, $this->vendor, 50000);
    $this->actingAs($this->olga)->withSession(Guard::stepUp())->postJson(route('vendors.anonymise', $this->vendor), ['confirm_name' => 'PixelForge Studio'])
        ->assertStatus(409)->assertJsonPath('code', 'has_open_items');
    app(CurrentWorkspace::class)->runAs($this->workspace, fn () => app(PayoutService::class)->cancel($payout, $this->mark, 'Canceled for anonymisation'));
    $this->actingAs($this->olga)->withSession(Guard::stepUp())->post(route('vendors.anonymise', $this->vendor), ['confirm_name' => 'pixelforge studio'])->assertRedirect();

    $vendor = Vendor::query()->findOrFail($this->vendor->id);
    $account = VendorBankAccount::query()->findOrFail($this->current->id);

    expect($vendor->email)->toBeNull()
        ->and($vendor->phone)->toBeNull()
        ->and($vendor->anonymised_at)->not->toBeNull()
        ->and($account->account_holder_name)->toBe('Anonymised')
        ->and($account->iban)->toBe($account->fingerprint)
        ->and($account->last4)->toBe('2874');
});

it('keeps the stored tax id when a vendor update omits it', function (): void {
    $this->vendor->forceFill(['tax_id' => 'PL5213874411'])->save();
    $form = ['name' => 'PixelForge Studio', 'category' => 'contractor', 'default_currency' => 'EUR'];

    $this->actingAs($this->mark)->put(route('vendors.update', $this->vendor), $form)->assertRedirect();
    expect($this->vendor->refresh()->tax_id)->toBe('PL5213874411');

    $this->actingAs($this->mark)->put(route('vendors.update', $this->vendor), [...$form, 'tax_id' => 'PL1111111111'])->assertRedirect();
    expect($this->vendor->refresh()->tax_id)->toBe('PL1111111111');
});

it('creates vendors with onboarding details and lists them with filters', function (): void {
    $this->actingAs($this->mark)->post(route('vendors.store'), [
        'name' => 'Nordlicht Tools', 'category' => 'saas', 'email' => 'billing@nordlicht.example', 'default_currency' => 'EUR', 'country' => 'DE', 'tax_id' => 'DE123456789',
        'bank' => Guard::iban('Nordlicht Tools', 'EE222200221077345612'),
    ])->assertRedirect();
    $vendor = Vendor::query()->where('name', 'Nordlicht Tools')->sole();

    expect(VendorBankAccount::query()->where('vendor_id', $vendor->id)->value('source')?->value)->toBe('onboarding');

    $this->actingAs($this->mark)->postJson(route('vendors.store'), ['name' => 'nordlicht tools', 'category' => 'saas', 'default_currency' => 'EUR'])
        ->assertStatus(422)->assertJsonPath('code', 'vendor_name_taken');

    $this->actingAs($this->dmitry)->get(route('vendors.index', ['attention' => 'pending_change']))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('vendors/Index')
        ->has('vendors.data', 1)
        ->where('vendors.data.0.name', 'Nordlicht Tools')
        ->where('vendors.data.0.pending_change.risk_band', 'low')
        ->where('counts.all', 2)
        ->where('counts.pending_change', 1)
        ->where('processing_notice', false));

    $this->actingAs($this->dmitry)->get(route('vendors.show', $vendor))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('vendors/Show')
        ->where('vendor.tax_id_masked', '•••• 6789')
        ->missing('vendor.tax_id')
        ->has('accounts', 1)
        ->where('can.edit', false)
        ->where('can.reveal', true));

    $member = Guard::member($this->workspace, WorkspaceRole::Member);
    $this->actingAs($member)->get(route('vendors.index'))->assertForbidden();
});

it('exports unmasked bank details only to owners after step-up and audits the export', function (): void {
    $this->actingAs($this->anna)->getJson(route('vendors.export.unmasked'))->assertStatus(423);
    $this->actingAs($this->olga)->withSession(Guard::stepUp())->getJson(route('vendors.export.unmasked'))->assertForbidden();
    $this->flushSession();
    $response = $this->actingAs($this->anna)->withSession(Guard::stepUp())->getJson(route('vendors.export.unmasked'))->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($response->json('vendors.0.accounts.0.iban'))->toBe('PL61109010140000071219812874')
        ->and(AuditLog::query()->where('action', 'payflow.export.unmasked')->count())->toBe(1);
});

it('never flashes account numbers or the request text into the session when validation fails', function (): void {
    $this->actingAs($this->mark)->from(route('vendors.show', $this->vendor))->post(route('vendors.bank-changes.store', $this->vendor), [
        ...Guard::iban('PF Studio Payments UAB'),
        'source' => 'vendor_email',
        'requester_contact' => 'not-an-email',
        'change_request_text' => 'Pay to LT583250067741200519 today',
    ])->assertSessionHasErrors('requester_contact');

    $old = (array) session()->get('_old_input', []);

    expect($old)->not->toHaveKey('iban')
        ->and($old)->not->toHaveKey('change_request_text')
        ->and(json_encode(session()->all()))->not->toContain('LT583250067741200519');
});

it('never flashes the bank details of a new vendor when validation fails', function (): void {
    $this->actingAs($this->mark)->from(route('vendors.create'))->post(route('vendors.store'), [
        'name' => '',
        'bank' => Guard::iban('Acme Dev GmbH', 'DE44500105175407324931'),
    ])->assertSessionHasErrors('name');

    expect(json_encode(session()->all()))->not->toContain('DE44500105175407324931');
});

it('refuses a verifier inside the trust delay before asking for a step-up', function (): void {
    $change = requestPixelChange($this);
    DB::table('workspace_user')->where('workspace_id', $this->workspace->id)->where('user_id', $this->dmitry->id)
        ->update(['guard_eligible_from' => now()->addHours(48)]);

    $this->actingAs($this->dmitry)->postJson(route('guard.bank-changes.verify', $change), [
        'method' => 'callback_known_phone',
        'checklist' => ['called_known_number' => true, 'confirmed_holder_name' => true, 'confirmed_last4' => true, 'confirmed_request_origin' => true],
    ])->assertStatus(409)->assertJsonPath('code', 'not_eligible_yet');

    expect($change->refresh()->status->value)->toBe('pending_verification');
});
