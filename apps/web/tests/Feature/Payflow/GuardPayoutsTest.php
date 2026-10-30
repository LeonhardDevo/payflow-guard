<?php

declare(strict_types=1);

use App\Domain\Guard\Enums\PayoutStatus;
use App\Domain\Guard\Enums\TrustedContactStatus;
use App\Domain\Guard\Models\GuardAlert;
use App\Domain\Guard\Models\PayoutApproval;
use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\TrustedConfirmation;
use App\Domain\Guard\Models\TrustedContact;
use App\Domain\Guard\Models\Vendor;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
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
});

function guardVendor(Workspace $workspace, User $requester, User $verifier, string $name, string $iban, string $currency, int $releasedHistory = 0, int $historyAmount = 32000): Vendor
{
    $vendor = Guard::vendor($workspace, $requester, ['name' => $name, 'country' => substr($iban, 0, 2), 'default_currency' => $currency]);
    $account = $requester->is($verifier)
        ? Guard::existingAccount($vendor, $requester, Guard::iban($name, $iban, $currency))
        : Guard::verifiedAccount($vendor, $requester, $verifier, Guard::iban($name, $iban, $currency));
    Guard::backdateVerification($account, 400);

    for ($i = 0; $i < $releasedHistory; $i++) {
        $payout = new PayoutRequest;
        $payout->forceFill([
            'workspace_id' => $workspace->id,
            'number' => 'HIST-'.$vendor->id.'-'.$i,
            'vendor_id' => $vendor->id,
            'vendor_bank_account_id' => $account->id,
            'bank_fingerprint' => $account->fingerprint,
            'amount_minor' => $historyAmount,
            'currency' => $currency,
            'description' => 'History',
            'status' => PayoutStatus::Released,
            'required_approvals' => 1,
            'approvals_count' => 1,
            'requested_by' => $requester->id,
            'submitted_at' => now()->subDays(60 + 30 * $i),
            'approved_at' => now()->subDays(60 + 30 * $i),
            'released_by' => $verifier->id,
            'released_at' => now()->subDays(59 + 30 * $i),
            'paid_reference' => 'TRF',
            'paid_on' => now()->subDays(59 + 30 * $i)->toDateString(),
            'created_at' => now()->subDays(61 + 30 * $i),
        ])->save();
    }

    return $vendor->refresh();
}

it('auto-approves small low-risk payouts and lets only another person release them', function (): void {
    $vendor = guardVendor($this->workspace, $this->mark, $this->dmitry, 'CloudNest Hosting', 'LT583250067741200519', 'USD', 3);

    $this->actingAs($this->mark)->post(route('payouts.store'), [
        'vendor_id' => $vendor->id, 'amount' => '320.00', 'currency' => 'USD', 'description' => 'Hosting — November', 'submit' => true,
    ])->assertRedirect();
    $payout = PayoutRequest::query()->where('status', 'approved')->sole();

    expect($payout->auto_approved)->toBeTrue()
        ->and($payout->required_approvals)->toBe(0)
        ->and($payout->release_requires_other_user)->toBeTrue()
        ->and($payout->auto_approve_basis)->toEqual(['threshold' => 50000, 'cumulative_used' => 0, 'cumulative_cap' => 100000])
        ->and(AuditLog::query()->where('action', 'payflow.payout.auto_approved')->exists())->toBeTrue();

    $this->actingAs($this->mark)->postJson(route('payouts.release', $payout), ['paid_reference' => 'TRF-1', 'paid_on' => '2026-10-22'])
        ->assertStatus(409)->assertJsonPath('code', 'releaser_is_requester');
    $this->actingAs($this->anna)->post(route('payouts.release', $payout), ['paid_reference' => 'TRF-1', 'paid_on' => '2026-10-22'])->assertRedirect();

    expect($payout->refresh()->status)->toBe(PayoutStatus::Released)
        ->and($payout->released_by)->toBe($this->anna->id)
        ->and(AuditLog::query()->where('action', 'payflow.payout.released')->value('metadata')['paid_reference'] ?? null)->toBe('TRF-1');

    expect(fn () => DB::table('payout_requests')->where('id', $payout->id)->update(['released_by' => $this->mark->id]))->toThrow(QueryException::class);
});

it('enforces four eyes, one decision per round and dual approval above the threshold', function (): void {
    $vendor = guardVendor($this->workspace, $this->olga, $this->dmitry, 'Copywrite Lab', 'LT583250067741200519', 'USD', 3, 1250000);
    $payout = Guard::payout($this->workspace, $this->olga, $vendor, 1250000, 'USD');

    expect($payout->status)->toBe(PayoutStatus::PendingApproval)
        ->and($payout->required_approvals)->toBe(2)
        ->and(GuardAlert::query()->where('dedupe_key', 'po:'.$payout->id.':1')->exists())->toBeTrue();

    $this->actingAs($this->mark)->postJson(route('payouts.approve', $payout))->assertForbidden();
    $this->actingAs($this->olga)->postJson(route('payouts.approve', $payout))->assertStatus(409)->assertJsonPath('code', 'is_requester');
    $this->actingAs($this->dmitry)->post(route('payouts.approve', $payout))->assertRedirect();
    $this->actingAs($this->dmitry)->postJson(route('payouts.approve', $payout))->assertStatus(409)->assertJsonPath('code', 'already_decided');

    expect($payout->refresh()->approvals_count)->toBe(1)->and($payout->status)->toBe(PayoutStatus::PendingApproval);

    $this->actingAs($this->anna)->post(route('payouts.approve', $payout), ['comment' => 'Matches the Q4 order'])->assertRedirect();

    expect($payout->refresh()->status)->toBe(PayoutStatus::Approved)
        ->and(PayoutApproval::query()->where('payout_request_id', $payout->id)->pluck('channel')->map->value->all())->toBe(['web', 'web'])
        ->and(GuardAlert::query()->where('dedupe_key', 'po:'.$payout->id.':1')->value('status')?->value)->toBe('resolved');

    expect(fn () => DB::table('payout_approvals')->insert([
        'workspace_id' => $this->workspace->id, 'payout_request_id' => $payout->id, 'user_id' => $this->anna->id, 'decision' => 'approved', 'channel' => 'web',
        'amount_minor' => 1, 'currency' => 'USD', 'bank_fingerprint' => str_repeat('a', 64), 'risk_band' => 'low', 'approval_round' => 1, 'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('requires a fresh step-up to approve medium-risk payouts and records the method', function (): void {
    $vendor = guardVendor($this->workspace, $this->mark, $this->dmitry, 'Ivan Smirnov', 'GE44TB7741936582010047', 'USD', 3, 200000);
    $this->actingAs($this->mark)->post(route('payouts.store'), [
        'vendor_id' => $vendor->id, 'amount' => '7200.00', 'currency' => 'USD', 'description' => 'Backend development', 'due_date' => '2026-10-23', 'submit' => true,
    ]);
    $payout = PayoutRequest::query()->where('vendor_id', $vendor->id)->where('status', 'pending_approval')->sole();

    expect($payout->risk_score)->toBe(30)
        ->and($payout->risk_band?->value)->toBe('medium')
        ->and(collect($payout->risk_signals)->pluck('code')->all())->toBe(['unusual_amount', 'urgent_due'])
        ->and($payout->telegram_allowed)->toBeTrue();

    $this->actingAs($this->dmitry)->postJson(route('payouts.approve', $payout))
        ->assertStatus(423)->assertJsonPath('code', 'step_up_required')->assertJsonPath('methods', ['totp']);
    $this->actingAs($this->dmitry)->withSession(Guard::stepUp())->post(route('payouts.approve', $payout))->assertRedirect();

    expect($payout->refresh()->status)->toBe(PayoutStatus::Approved)
        ->and(PayoutApproval::query()->where('payout_request_id', $payout->id)->value('step_up_method')?->value)->toBe('totp');
});

it('holds payouts when bank details change and reopens them with a new round when the change is rejected', function (): void {
    $vendor = guardVendor($this->workspace, $this->mark, $this->dmitry, 'PixelForge Studio', 'PL61109010140000071219812874', 'EUR');
    $payout = Guard::payout($this->workspace, $this->mark, $vendor, 480000);
    $this->actingAs($this->dmitry)->post(route('payouts.approve', $payout));
    expect($payout->refresh()->status)->toBe(PayoutStatus::Approved);

    $this->actingAs($this->mark)->post(route('vendors.bank-changes.store', $vendor), [
        ...Guard::iban('PF Studio Payments UAB'), 'source' => 'vendor_email', 'requester_contact' => 'billing@pixelf0rge.example',
        'change_request_text' => 'Please pay urgently to the new account, do not call.',
    ])->assertRedirect();
    $change = VendorBankAccount::query()->where('vendor_id', $vendor->id)->where('status', 'pending_verification')->sole();

    expect($payout->refresh()->status)->toBe(PayoutStatus::OnHold)
        ->and($payout->hold_reason?->value)->toBe('bank_details_changed')
        ->and($payout->approvals_count)->toBe(0)
        ->and(PayoutApproval::query()->where('payout_request_id', $payout->id)->whereNotNull('invalidated_at')->count())->toBe(1)
        ->and(GuardAlert::query()->where('dedupe_key', 'po-hold:'.$payout->id)->exists())->toBeTrue();

    $this->actingAs($this->mark)->postJson(route('payouts.resubmit', $payout))->assertStatus(409)->assertJsonPath('code', 'hold_not_cleared');
    $this->actingAs($this->dmitry)->withSession(Guard::stepUp())->post(route('guard.bank-changes.reject', $change), ['reason' => 'Lookalike domain, confirmed fraud by phone'])->assertRedirect();

    expect($payout->refresh()->status)->toBe(PayoutStatus::PendingApproval)
        ->and($payout->approval_round)->toBe(2)
        ->and($payout->approvals_count)->toBe(0)
        ->and(GuardAlert::query()->where('dedupe_key', 'po-hold:'.$payout->id)->value('status')?->value)->toBe('resolved');
});

it('blocks submission when not enough eligible people can approve', function (): void {
    DB::table('workspace_user')->where('workspace_id', $this->workspace->id)->whereIn('user_id', [$this->dmitry->id, $this->olga->id])
        ->update(['guard_eligible_from' => now()->addHours(48)]);
    $vendor = guardVendor($this->workspace, $this->mark, $this->anna, 'Copywrite Lab', 'LT583250067741200519', 'USD', 3, 1250000);
    $payout = Guard::payout($this->workspace, $this->mark, $vendor, 1250000, 'USD', submit: false);

    $this->actingAs($this->mark)->postJson(route('payouts.submit', $payout))
        ->assertStatus(422)
        ->assertJsonPath('code', 'insufficient_approvers')
        ->assertJsonPath('required', 2)
        ->assertJsonPath('eligible', 1)
        ->assertJsonPath('trusted_contact', false);

    expect($payout->refresh()->status)->toBe(PayoutStatus::Draft)
        ->and(GuardAlert::query()->where('dedupe_key', 'ia:'.$this->workspace->id)->exists())->toBeTrue();
});

it('flags duplicates and split payments and keeps them out of Telegram', function (): void {
    $vendor = guardVendor($this->workspace, $this->mark, $this->dmitry, 'Ivan Smirnov', 'GE44TB7741936582010047', 'USD', 3, 200000);
    Guard::payout($this->workspace, $this->mark, $vendor, 600000, 'USD', invoice: 'IS-1');
    $split = Guard::payout($this->workspace, $this->mark, $vendor, 500000, 'USD', invoice: 'IS-2');
    $duplicate = Guard::payout($this->workspace, $this->mark, $vendor, 100000, 'USD', invoice: 'is-1');

    expect(collect($split->risk_signals)->pluck('code')->all())->toContain('split_suspected')
        ->and($split->required_approvals)->toBe(2)
        ->and($split->split_group_total_minor)->toBe(1100000)
        ->and($split->telegram_allowed)->toBeFalse()
        ->and(collect($duplicate->risk_signals)->pluck('code')->all())->toContain('duplicate_suspected');
});

it('runs the trusted-contact confirmation for a solo owner and delays the release', function (): void {
    $solo = Guard::workspace(Workspace::factory()->create(['timezone' => 'Europe/Riga']));
    $owner = Guard::member($solo, WorkspaceRole::Owner);
    TrustedContact::query()->create([
        'workspace_id' => $solo->id, 'name' => 'Pat Reviewer', 'email' => 'trusted@e2e.example', 'locale' => 'en',
        'status' => TrustedContactStatus::Active, 'verified_at' => now()->subDays(10), 'active_from' => now()->subDays(7), 'created_by' => $owner->id,
    ]);
    $vendor = guardVendor($solo, $owner, $owner, 'Solo Vendor', 'LT583250067741200519', 'EUR');
    $payout = Guard::payout($solo, $owner, $vendor, 90000);
    $email = collect(Payflow::emails())->firstWhere('reference.type', 'trusted_confirmation');
    preg_match('#/tc/([A-Za-z0-9]{48})#', (string) $email['text'], $match);

    expect($payout->status)->toBe(PayoutStatus::PendingApproval)
        ->and($payout->required_approvals)->toBe(1)
        ->and($email['to'])->toBe('trusted@e2e.example')
        ->and($email['subject'])->toContain($solo->name)
        ->and($match)->toHaveCount(2);

    $this->get(route('trusted.show', $match[1]))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn (Assert $page) => $page->component('trusted/Confirm')
            ->where('confirmation.subject', 'payout_request')
            ->where('confirmation.status', 'pending')
            ->where('confirmation.snapshot.amount_minor', 90000)
            ->where('confirmation.snapshot.last4', '0519')
            ->missing('confirmation.snapshot.fingerprint8')
            ->has('disclaimer_url'));

    $this->post(route('trusted.decide', $match[1]), ['decision' => 'confirm'])->assertRedirect();
    $this->postJson(route('trusted.decide', $match[1]), ['decision' => 'confirm'])->assertStatus(410);
    $approval = PayoutApproval::query()->where('payout_request_id', $payout->id)->sole();

    expect($payout->refresh()->status)->toBe(PayoutStatus::Approved)
        ->and($approval->channel->value)->toBe('trusted_contact')
        ->and($approval->user_id)->toBeNull()
        ->and($approval->trusted_confirmation_id)->toBe(TrustedConfirmation::query()->sole()->id)
        ->and($payout->release_not_before?->toIso8601String())->toBe(now()->addHours(24)->toIso8601String());

    $this->actingAs($owner)->postJson(route('payouts.release', $payout), ['paid_reference' => 'TRF-9', 'paid_on' => '2026-10-22'])
        ->assertStatus(409)->assertJsonPath('code', 'release_delayed');

    Payflow::freeze('2026-10-23T11:00:00Z');
    $this->actingAs($owner)->post(route('payouts.release', $payout), ['paid_reference' => 'TRF-9', 'paid_on' => '2026-10-23'])->assertRedirect();
    expect($payout->refresh()->status)->toBe(PayoutStatus::Released);
});

it('lets the trusted contact decline a payout', function (): void {
    $solo = Guard::workspace(Workspace::factory()->create());
    $owner = Guard::member($solo, WorkspaceRole::Owner);
    TrustedContact::query()->create([
        'workspace_id' => $solo->id, 'name' => 'Pat Reviewer', 'email' => 'trusted@e2e.example', 'locale' => 'ru',
        'status' => TrustedContactStatus::Active, 'verified_at' => now()->subDays(10), 'active_from' => now()->subDays(7), 'created_by' => $owner->id,
    ]);
    $vendor = guardVendor($solo, $owner, $owner, 'Solo Vendor', 'LT583250067741200519', 'EUR');
    $payout = Guard::payout($solo, $owner, $vendor, 90000);
    $email = collect(Payflow::emails())->firstWhere('reference.type', 'trusted_confirmation');
    preg_match('#/tc/([A-Za-z0-9]{48})#', (string) $email['text'], $match);

    expect($email['subject'])->toStartWith('Подтвердите платёж');

    $this->post(route('trusted.decide', $match[1]), ['decision' => 'decline', 'comment' => 'I do not know this vendor'])->assertRedirect();

    expect($payout->refresh()->status)->toBe(PayoutStatus::Rejected)
        ->and(TrustedConfirmation::query()->sole()->status->value)->toBe('declined');
});

it('puts vendor payouts on hold, requires the hold to be cleared and supports cancel', function (): void {
    $vendor = guardVendor($this->workspace, $this->mark, $this->dmitry, 'QA Crew OÜ', 'EE537700771003819264', 'EUR');
    $payout = Guard::payout($this->workspace, $this->mark, $vendor, 290000);

    $this->actingAs($this->mark)->post(route('vendors.hold', $vendor), ['reason' => 'Contract under review'])->assertRedirect();
    expect($payout->refresh()->status)->toBe(PayoutStatus::OnHold)->and($payout->hold_reason?->value)->toBe('vendor_on_hold');

    $this->actingAs($this->mark)->postJson(route('payouts.resubmit', $payout))->assertStatus(409);
    $this->actingAs($this->mark)->post(route('vendors.release-hold', $vendor))->assertRedirect();
    $this->actingAs($this->mark)->post(route('payouts.resubmit', $payout))->assertRedirect();

    expect($payout->refresh()->status)->toBe(PayoutStatus::PendingApproval)->and($payout->approval_round)->toBe(2);

    $this->actingAs($this->mark)->postJson(route('payouts.cancel', $payout), ['reason' => 'no'])->assertStatus(422);
    $this->actingAs($this->mark)->post(route('payouts.cancel', $payout), ['reason' => 'Vendor sent a corrected invoice'])->assertRedirect();

    expect($payout->refresh()->status)->toBe(PayoutStatus::Canceled)
        ->and(GuardAlert::query()->where('dedupe_key', 'po:'.$payout->id.':2')->value('status')?->value)->toBe('resolved');
});

it('validates payout forms, keeps amounts immutable after submit and isolates workspaces', function (): void {
    $vendor = guardVendor($this->workspace, $this->mark, $this->dmitry, 'QA Crew OÜ', 'EE537700771003819264', 'EUR');
    $this->actingAs($this->mark)->postJson(route('payouts.store'), ['vendor_id' => $vendor->id, 'amount' => '-5', 'currency' => 'EUR', 'description' => ''])
        ->assertStatus(422)->assertJsonValidationErrors(['amount', 'description']);
    $this->actingAs($this->mark)->postJson(route('payouts.store'), ['vendor_id' => $vendor->id, 'amount' => '10.00', 'currency' => 'USD', 'description' => 'x', 'submit' => true])
        ->assertStatus(422)->assertJsonPath('code', 'currency_mismatch');

    $payout = Guard::payout($this->workspace, $this->mark, $vendor, 100000);
    $this->actingAs($this->mark)->putJson(route('payouts.update', $payout), ['vendor_id' => $vendor->id, 'amount' => '5.00', 'currency' => 'EUR', 'description' => 'x'])
        ->assertStatus(409)->assertJsonPath('code', 'payout_immutable');
    $this->actingAs($this->anna)->post(route('payouts.approve', $payout));
    $this->actingAs($this->anna)->postJson(route('payouts.release', $payout), ['paid_reference' => 'X', 'paid_on' => '2026-11-30'])
        ->assertStatus(422)->assertJsonValidationErrors(['paid_on']);

    [$stranger] = $this->member(WorkspaceRole::Owner);
    $this->actingAs($stranger)->get(route('payouts.show', $payout))->assertNotFound();
    $this->actingAs($stranger)->get(route('vendors.show', $vendor))->assertNotFound();
});

it('refuses an approver inside the trust delay before asking for a step-up', function (): void {
    $vendor = guardVendor($this->workspace, $this->mark, $this->anna, 'Copywrite Lab', 'LT583250067741200519', 'USD', 3, 1250000);
    $payout = Guard::payout($this->workspace, $this->mark, $vendor, 1250000, 'USD');
    DB::table('workspace_user')->where('workspace_id', $this->workspace->id)->where('user_id', $this->dmitry->id)
        ->update(['guard_eligible_from' => now()->addHours(48)]);

    $this->actingAs($this->dmitry)->postJson(route('payouts.approve', $payout))
        ->assertStatus(409)
        ->assertJsonPath('code', 'not_eligible_yet');

    expect($payout->refresh()->approvals_count)->toBe(0);
});
