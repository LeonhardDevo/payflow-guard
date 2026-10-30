<?php

declare(strict_types=1);

use App\Domain\Guard\Enums\PayoutStatus;
use App\Domain\Guard\Escalation\GuardEscalations;
use App\Domain\Guard\Models\GuardAlert;
use App\Domain\Guard\Models\PayoutApproval;
use App\Domain\Guard\Models\ReceivingDetailsVersion;
use App\Domain\Guard\Models\TrustedContact;
use App\Domain\Guard\Trusted\TrustedContactService;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Workspaces\InvitationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Payflow\Support\Guard;
use Tests\Feature\Payflow\Support\Payflow;

beforeEach(function (): void {
    Payflow::freeze('2026-10-22T10:00:00Z');
    Mail::fake();
    $this->workspace = Guard::workspace(Workspace::factory()->create(['timezone' => 'Europe/Riga']));
    $this->anna = Guard::member($this->workspace, WorkspaceRole::Owner);
    $this->dmitry = Guard::member($this->workspace, WorkspaceRole::Approver);
    $this->olga = Guard::member($this->workspace, WorkspaceRole::Admin);
});

function guardSettingsPayload(array $overrides = []): array
{
    return [
        'thresholds' => [
            ['currency' => 'USD', 'auto_approve_below' => '500.00', 'dual_approval_from' => '10000.00', 'auto_approve_cumulative' => '1000.00'],
            ['currency' => 'EUR', 'auto_approve_below' => '500.00', 'dual_approval_from' => '10000.00', 'auto_approve_cumulative' => '1000.00'],
        ],
        'cooling_off_hours_medium' => 24,
        'cooling_off_hours_high' => 48,
        'allow_telegram_approvals' => true,
        'escalate_pending_after_hours' => 24,
        'guard_trust_delay_hours' => 72,
        'require_second_factor' => false,
        'solo_release_delay_hours' => 24,
        ...$overrides,
    ];
}

it('steps up with a TOTP code and audits successes and failures', function (): void {
    $code = app(Google2FA::class)->getCurrentOtp('JBSWY3DPEHPK3PXP');

    $this->actingAs($this->dmitry)->postJson(route('guard.step-up'), ['method' => 'totp', 'code' => '000000'])->assertStatus(422);
    $this->actingAs($this->dmitry)->postJson(route('guard.step-up'), ['method' => 'password', 'password' => 'password'])->assertStatus(422);
    $this->actingAs($this->dmitry)->postJson(route('guard.step-up'), ['method' => 'totp', 'code' => $code])->assertNoContent()
        ->assertSessionHas('payflow.stepup_method', 'totp');

    expect(AuditLog::query()->where('action', 'payflow.stepup.failed')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'payflow.stepup.succeeded')->value('metadata'))->toBe(['method' => 'totp']);

    $plain = Guard::member($this->workspace, WorkspaceRole::Member, twoFactor: false);
    $this->actingAs($plain)->postJson(route('guard.step-up'), ['method' => 'password', 'password' => 'password'])->assertNoContent();
});

it('blocks privileged members without a second factor when the workspace requires it', function (): void {
    WorkspaceProvisioner::settingsFor($this->workspace)->forceFill(['require_second_factor' => true])->save();
    $approver = Guard::member($this->workspace, WorkspaceRole::Approver, twoFactor: false);

    $this->actingAs($approver)->postJson(route('guard.step-up'), ['method' => 'password', 'password' => 'password'])->assertStatus(422);

    $this->actingAs($approver)->get(route('guard.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('guard/Index')
        ->where('me.missing_second_factor', true)
        ->where('pf.stepup.second_factor_required', true)
        ->where('pf.stepup.has_second_factor', false)
        ->where('pf.stepup.methods', []));

    $version = ReceivingDetailsVersion::query()->create([
        'workspace_id' => $this->workspace->id, 'version' => 1, 'details' => 'IBAN LT58 3250 0677 4120 0519', 'details_hash' => str_repeat('b', 64),
        'status' => 'pending_confirmation', 'requested_by' => $this->anna->id, 'requested_at' => now(),
    ]);
    $this->actingAs($approver)->withSession(Guard::stepUp())->postJson(route('settings.receiving.confirm', $version))
        ->assertStatus(403)->assertJsonPath('code', 'second_factor_required')->assertJsonPath('setup_url', route('security.edit'));

    expect(GuardAlert::query()->where('dedupe_key', '2fa:'.$approver->id)->exists())->toBeTrue();
});

it('updates Guard settings with step-up and flags loosening changes as critical', function (): void {
    $this->actingAs($this->anna)->putJson(route('settings.guard.update'), guardSettingsPayload())->assertStatus(423);
    $this->actingAs($this->olga)->withSession(Guard::stepUp())->putJson(route('settings.guard.update'), guardSettingsPayload())->assertForbidden();
    $this->actingAs($this->anna)->withSession(Guard::stepUp())->putJson(route('settings.guard.update'), guardSettingsPayload([
        'thresholds' => [['currency' => 'USD', 'auto_approve_below' => '500.00', 'dual_approval_from' => '400.00']],
    ]))->assertStatus(422)->assertJsonValidationErrors(['thresholds.0.dual_approval_from']);

    $this->actingAs($this->anna)->withSession(Guard::stepUp())->put(route('settings.guard.update'), guardSettingsPayload([
        'cooling_off_hours_high' => 72,
    ]))->assertRedirect();
    $tightened = GuardAlert::query()->where('type', 'guard_settings_changed')->sole();

    $this->actingAs($this->anna)->withSession(Guard::stepUp())->put(route('settings.guard.update'), guardSettingsPayload([
        'thresholds' => [['currency' => 'USD', 'auto_approve_below' => '900.00', 'dual_approval_from' => '10000.00', 'auto_approve_cumulative' => null]],
    ]))->assertRedirect();
    $loosened = GuardAlert::query()->where('type', 'guard_settings_changed')->latest('id')->first();
    $settings = WorkspaceProvisioner::settingsFor($this->workspace);

    expect($tightened->severity->value)->toBe('warning')
        ->and($loosened?->severity->value)->toBe('critical')
        ->and($settings->approval_thresholds)->toEqual(['USD' => ['auto_approve_below_minor' => 90000, 'dual_approval_from_minor' => 1000000, 'auto_approve_cumulative_minor' => null]])
        ->and(AuditLog::query()->where('action', 'payflow.settings.thresholds_updated')->count())->toBe(1);

    $this->actingAs($this->dmitry)->get(route('settings.guard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('settings/Guard')
        ->where('settings.thresholds.0.currency', 'USD')
        ->where('settings.thresholds.0.auto_approve_below', ['minor' => 90000, 'currency' => 'USD'])
        ->where('settings.cooling_off_hours_high', 48)
        ->has('eligible_approvers', 3)
        ->where('trusted_contact', null)
        ->where('can_edit', false));
});

it('lets only owners grant privileged roles, with step-up, trust delay and alerts', function (): void {
    $this->actingAs($this->olga)->withSession(Guard::stepUp())->post(route('settings.members.invitations.store'), ['email' => 'new@payflow.test', 'role' => 'approver'])
        ->assertRedirect();
    $this->actingAs($this->olga)->withSession(Guard::stepUp())->postJson(route('settings.members.invitations.store'), ['email' => 'new@payflow.test', 'role' => 'approver'])
        ->assertStatus(403)->assertJsonPath('error', 'insufficient_role');
    expect(WorkspaceInvitation::query()->count())->toBe(0);

    $this->flushSession();
    $this->actingAs($this->anna)->postJson(route('settings.members.invitations.store'), ['email' => 'new@payflow.test', 'role' => 'approver'])->assertStatus(423);
    expect(WorkspaceInvitation::query()->count())->toBe(0);

    $this->actingAs($this->anna)->withSession(Guard::stepUp())->post(route('settings.members.invitations.store'), ['email' => 'new@payflow.test', 'role' => 'approver'])->assertRedirect();
    $invitation = WorkspaceInvitation::query()->sole();
    $newcomer = User::factory()->create(['email' => 'new@payflow.test']);
    app(InvitationService::class)->accept($invitation, $newcomer);

    $eligibleFrom = DB::table('workspace_user')->where('workspace_id', $this->workspace->id)->where('user_id', $newcomer->id)->value('guard_eligible_from');

    expect(CarbonImmutable::parse($eligibleFrom)->toIso8601String())->toBe(now()->addHours(72)->toIso8601String())
        ->and(GuardAlert::query()->where('type', 'guard_role_granted')->value('severity')?->value)->toBe('critical')
        ->and(AuditLog::query()->where('action', 'payflow.member.guard_role_granted')->exists())->toBeTrue()
        ->and(DB::table('outbox_messages')->where('topic', 'email.send')->whereRaw("payload->'reference'->>'type' = 'guard_alert'")->whereRaw("payload->'reference'->>'id' = ?", [(string) GuardAlert::query()->where('type', 'guard_role_granted')->value('id')])->count())->toBeGreaterThanOrEqual(3);

    $member = Guard::member($this->workspace, WorkspaceRole::Member);
    $this->flushSession();
    $this->actingAs($this->anna)->patchJson(route('settings.members.update', $member), ['role' => 'finance'])->assertStatus(423);
    $this->actingAs($this->anna)->withSession(Guard::stepUp())->patch(route('settings.members.update', $member), ['role' => 'finance'])->assertRedirect();

    expect($member->roleIn($this->workspace))->toBe(WorkspaceRole::Finance)
        ->and(DB::table('workspace_user')->where('user_id', $member->id)->value('guard_eligible_from'))->not->toBeNull();
});

it('invalidates approvals and reopens approved payouts when an approver is removed', function (): void {
    $mark = Guard::member($this->workspace, WorkspaceRole::Finance);
    $vendor = Guard::vendor($this->workspace, $mark, ['name' => 'QA Crew OÜ', 'country' => 'EE']);
    Guard::verifiedAccount($vendor, $mark, $this->dmitry, Guard::iban('QA Crew OÜ', 'EE537700771003819264'));
    $payout = Guard::payout($this->workspace, $mark, $vendor->refresh(), 50000);
    $this->actingAs($this->dmitry)->withSession(Guard::stepUp())->post(route('payouts.approve', $payout));
    expect($payout->refresh()->status)->toBe(PayoutStatus::Approved);

    $this->actingAs($this->anna)->delete(route('settings.members.destroy', $this->dmitry))->assertRedirect();

    expect($payout->refresh()->status)->toBe(PayoutStatus::PendingApproval)
        ->and($payout->approval_round)->toBe(2)
        ->and(PayoutApproval::query()->where('payout_request_id', $payout->id)->value('invalidated_at'))->not->toBeNull()
        ->and(GuardAlert::query()->where('type', 'guard_role_revoked')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'payflow.payout.approvals_invalidated')->exists())->toBeTrue();
});

it('adds, verifies and declines trusted contacts through signed links', function (): void {
    $this->actingAs($this->anna)->withSession(Guard::stepUp())->postJson(route('settings.guard.trusted-contact.store'), ['name' => 'Self', 'email' => $this->dmitry->email, 'locale' => 'en'])
        ->assertStatus(422)->assertJsonPath('code', 'trusted_contact_is_member');
    $this->actingAs($this->anna)->withSession(Guard::stepUp())->post(route('settings.guard.trusted-contact.store'), ['name' => 'Pat Reviewer', 'email' => 'Pat@Outside.example', 'locale' => 'ru'])
        ->assertRedirect();
    $contact = TrustedContact::query()->sole();
    $email = collect(Payflow::emails())->firstWhere('reference.type', 'trusted_contact_verification');
    preg_match('#(https?://\S+/tc/verify/'.$contact->id.'\?\S+)#', (string) $email['text'], $match);

    expect($contact->email)->toBe('pat@outside.example')
        ->and($contact->status->value)->toBe('pending_verification')
        ->and($email['subject'])->toContain('доверенным лицом')
        ->and(GuardAlert::query()->where('dedupe_key', 'tc:'.$contact->id.':pending_verification')->value('severity')?->value)->toBe('critical');

    $this->get(route('trusted.verify', ['token' => $contact->id]))->assertForbidden();
    $this->get($match[1])->assertOk()->assertInertia(fn (Assert $page) => $page->component('trusted/Verified')->where('state', 'verified')->where('locale', 'ru'));

    expect($contact->refresh()->status->value)->toBe('active')
        ->and($contact->active_from?->toIso8601String())->toBe(now()->addHours(72)->toIso8601String());

    $decline = app(TrustedContactService::class)->verificationUrl($contact, true);
    $this->get($decline)->assertOk()->assertInertia(fn (Assert $page) => $page->where('state', 'decline_prompt'));
    expect($contact->refresh()->status->value)->toBe('active');
    $this->post($decline)->assertOk()->assertInertia(fn (Assert $page) => $page->where('state', 'declined'));

    expect($contact->refresh()->status->value)->toBe('revoked')
        ->and(AuditLog::query()->where('action', 'payflow.trusted_contact.revoked')->exists())->toBeTrue();
});

it('changes invoice bank details only after a second person confirms and the delay passes', function (): void {
    $active = ReceivingDetailsVersion::query()->create([
        'workspace_id' => $this->workspace->id, 'version' => 1, 'details' => 'IBAN LV09DEMO0551043018275', 'details_hash' => str_repeat('a', 64),
        'status' => 'active', 'requested_by' => $this->anna->id, 'requested_at' => now()->subDays(100),
    ]);
    WorkspaceProvisioner::settingsFor($this->workspace)->forceFill(['active_receiving_details_id' => $active->id])->save();

    $this->actingAs($this->anna)->postJson(route('settings.receiving.request'), ['details' => 'IBAN LT58 3250 0677 4120 0519'])->assertStatus(423);
    $this->actingAs($this->anna)->withSession(Guard::stepUp())->post(route('settings.receiving.request'), ['details' => "Northstar SIA\nIBAN LT58 3250 0677 4120 0519"])->assertRedirect();
    $version = ReceivingDetailsVersion::query()->where('version', 2)->sole();

    expect($version->status->value)->toBe('pending_confirmation')
        ->and(GuardAlert::query()->where('dedupe_key', 'rdv:'.$version->id)->value('severity')?->value)->toBe('critical');

    $this->actingAs($this->anna)->withSession(Guard::stepUp())->postJson(route('settings.receiving.confirm', $version))->assertStatus(409)->assertJsonPath('code', 'is_requester');
    $this->actingAs($this->dmitry)->withSession(Guard::stepUp())->post(route('settings.receiving.confirm', $version))->assertRedirect();

    expect($version->refresh()->status->value)->toBe('scheduled')
        ->and($version->confirmation_mode?->value)->toBe('second_person')
        ->and($version->effective_at?->toIso8601String())->toBe(now()->addHours(24)->toIso8601String())
        ->and(app(GuardEscalations::class)->run()['receiving_details_activated'])->toBe(0);

    Payflow::freeze('2026-10-23T10:30:00Z');
    $result = app(GuardEscalations::class)->run();

    expect($result['receiving_details_activated'])->toBe(1)
        ->and($version->refresh()->status->value)->toBe('active')
        ->and($active->refresh()->status->value)->toBe('superseded')
        ->and(WorkspaceProvisioner::settingsFor($this->workspace)->active_receiving_details_id)->toBe($version->id)
        ->and(GuardAlert::query()->where('dedupe_key', 'rd:'.$version->id)->exists())->toBeTrue()
        ->and(GuardAlert::query()->where('dedupe_key', 'rdv:'.$version->id)->value('status')?->value)->toBe('resolved');
});

it('schedules solo invoice bank detail changes without confirmation and keeps the alert open', function (): void {
    $solo = Guard::workspace(Workspace::factory()->create());
    $owner = Guard::member($solo, WorkspaceRole::Owner);

    $this->actingAs($owner)->withSession(Guard::stepUp())->post(route('settings.receiving.request'), ['details' => 'IBAN LT58 3250 0677 4120 0519'])->assertRedirect();
    $version = ReceivingDetailsVersion::query()->forWorkspace($solo)->sole();

    expect($version->status->value)->toBe('scheduled')
        ->and($version->confirmation_mode?->value)->toBe('unconfirmed_solo');

    $this->actingAs($owner)->post(route('settings.receiving.cancel', $version))->assertRedirect();
    expect($version->refresh()->status->value)->toBe('canceled');
});
