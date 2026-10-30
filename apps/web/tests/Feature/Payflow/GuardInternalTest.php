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
use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Payflow\Support\Guard;
use Tests\Feature\Payflow\Support\Payflow;

beforeEach(function (): void {
    Payflow::freeze('2026-10-22T10:00:00Z');
    $this->workspace = Guard::workspace(Workspace::factory()->create(['timezone' => 'Europe/Riga']));
    $this->anna = Guard::member($this->workspace, WorkspaceRole::Owner);
    $this->mark = Guard::member($this->workspace, WorkspaceRole::Finance);
    $this->dmitry = Guard::member($this->workspace, WorkspaceRole::Approver);
    Guard::telegram($this->dmitry, $this->workspace, 700000003);
    $this->vendor = Guard::vendor($this->workspace, $this->mark, ['name' => 'Ivan Smirnov', 'legal_name' => 'IE Ivan Smirnov', 'country' => 'GE', 'default_currency' => 'USD']);
    Guard::verifiedAccount($this->vendor, $this->mark, $this->dmitry, Guard::iban('Ivan Smirnov', 'GE44TB7741936582010047', 'USD'));
    $this->payout = Guard::payout($this->workspace, $this->mark, $this->vendor->refresh(), 72000, 'USD');
    $this->fp8 = substr((string) $this->payout->bank_fingerprint, 0, 8);
});

function decision(object $test, int $payoutId, array $body): TestResponse
{
    return $test->internal('POST', '/internal/v1/payflow/bot/payouts/'.$payoutId.'/decision', [
        'telegram_user_id' => 700000003, 'decision' => 'approve', 'fp8' => $test->fp8, 'confirmed' => false, ...$body,
    ]);
}

it('renders the approvals view with stored-action buttons for the linked approver', function (): void {
    $response = $this->internal('GET', '/internal/v1/payflow/bot/views/approvals?telegram_user_id=700000003')->assertOk();
    $buttons = collect($response->json('buttons'));

    expect($response->json('text_html'))->toContain($this->payout->number)
        ->and($response->json('text_html'))->not->toMatch('/\p{Extended_Pictographic}/u')
        ->and($buttons->pluck('callback')->filter()->values()->all())->toBe(['pf.pa:'.$this->payout->id.':'.$this->fp8, 'pf.pr:'.$this->payout->id.':'.$this->fp8])
        ->and($buttons->count())->toBeLessThanOrEqual(10);

    $this->internal('GET', '/internal/v1/payflow/bot/views/approvals?telegram_user_id=12345')->assertNotFound()->assertJson(['code' => 'not_linked']);
    $this->internal('GET', '/internal/v1/payflow/bot/views/approvals?telegram_user_id=700000003', null, 'ai')->assertForbidden();
    $this->internal('GET', '/internal/v1/payflow/bot/views/changes?telegram_user_id=700000003')->assertOk()->assertJsonPath('buttons', []);
    $this->internal('GET', '/internal/v1/payflow/bot/views/digest?telegram_user_id=700000003')->assertOk();
    $this->internal('GET', '/internal/v1/payflow/bot/views/overdue?telegram_user_id=700000003')->assertOk()->assertJsonPath('text_html', 'No overdue invoices.');
});

it('confirms and records Telegram approvals with the fingerprint check', function (): void {
    decision($this, $this->payout->id, [])->assertOk()->assertJsonPath('code', 'confirm_required')
        ->assertJsonPath('buttons.0.callback', 'pf.pac:'.$this->payout->id.':'.$this->fp8)
        ->assertJsonPath('buttons.1.callback', 'pf.x');
    decision($this, $this->payout->id, ['fp8' => '00000000', 'confirmed' => true])->assertOk()->assertJsonPath('code', 'details_changed');
    decision($this, $this->payout->id, ['confirmed' => true])->assertOk()->assertJsonPath('ok', true)->assertJsonPath('status', 'approved');

    expect(PayoutApproval::query()->sole()->channel->value)->toBe('telegram')
        ->and(PayoutApproval::query()->sole()->step_up_method)->toBeNull();

    decision($this, $this->payout->id, ['confirmed' => true])->assertOk()->assertJsonPath('code', 'not_pending');
});

it('keeps high-risk payouts web-only and rejects through Telegram with a comment', function (): void {
    $this->payout->forceFill(['telegram_allowed' => false])->save();

    decision($this, $this->payout->id, ['confirmed' => true])->assertOk()->assertJsonPath('code', 'web_only')
        ->assertJsonPath('buttons.0.url', route('payouts.show', $this->payout->id));
    decision($this, $this->payout->id, ['decision' => 'reject', 'confirmed' => true, 'comment' => 'Duplicate of the October invoice'])
        ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('status', 'rejected');
});

it('resolves the workspace from the payout, never from the Telegram context', function (): void {
    $other = Guard::workspace(Workspace::factory()->create());
    $otherOwner = Guard::member($other, WorkspaceRole::Owner);
    $otherFinance = Guard::member($other, WorkspaceRole::Finance);
    $other->addMember($this->dmitry, WorkspaceRole::Member);
    $vendor = Guard::vendor($other, $otherFinance, ['name' => 'Other Vendor', 'country' => 'LT']);
    Guard::verifiedAccount($vendor, $otherFinance, $otherOwner, Guard::iban('Other Vendor'));
    $foreign = Guard::payout($other, $otherFinance, $vendor->refresh(), 30000);

    decision($this, $foreign->id, ['fp8' => substr((string) $foreign->bank_fingerprint, 0, 8), 'confirmed' => true])->assertOk()->assertJsonPath('code', 'forbidden');
    expect($foreign->refresh()->status)->toBe(PayoutStatus::PendingApproval);

    $approverThere = Guard::member($other, WorkspaceRole::Approver);
    $this->workspace->addMember($approverThere, WorkspaceRole::Member);
    Guard::telegram($approverThere, $this->workspace, 700000099);
    $this->internal('POST', '/internal/v1/payflow/bot/payouts/'.$foreign->id.'/decision', [
        'telegram_user_id' => 700000099, 'decision' => 'approve', 'fp8' => substr((string) $foreign->bank_fingerprint, 0, 8), 'confirmed' => true,
    ])->assertOk()->assertJsonPath('ok', true);

    expect($foreign->refresh()->status)->toBe(PayoutStatus::Approved)
        ->and(PayoutApproval::query()->where('payout_request_id', $foreign->id)->value('workspace_id'))->toBe($other->id);
});

it('acknowledges alerts from Telegram for members with guard.alerts', function (): void {
    $alert = GuardAlert::query()->where('dedupe_key', 'po:'.$this->payout->id.':1')->sole();

    $this->internal('POST', '/internal/v1/payflow/bot/alerts/'.$alert->id.'/acknowledge', ['telegram_user_id' => 700000003])
        ->assertOk()->assertJsonPath('ok', true);

    expect($alert->refresh()->status->value)->toBe('acknowledged')->and($alert->acknowledged_by)->toBe($this->dmitry->id);
});

it('escalates stale approvals once a day and expires trusted confirmations', function (): void {
    Payflow::freeze('2026-10-23T12:00:00Z');
    $this->internal('POST', '/internal/v1/payflow/payouts/escalations/run', [])->assertOk()->assertJson(['escalated' => 1, 'confirmations_reminded' => 0, 'confirmations_expired' => 0, 'receiving_details_activated' => 0]);
    $this->internal('POST', '/internal/v1/payflow/payouts/escalations/run', [])->assertOk()->assertJsonPath('escalated', 0);

    expect(GuardAlert::query()->where('dedupe_key', 'po-stale:'.$this->payout->id)->exists())->toBeTrue()
        ->and($this->payout->refresh()->last_escalated_at?->toIso8601String())->toBe('2026-10-23T12:00:00+00:00');

    $contact = TrustedContact::query()->create([
        'workspace_id' => $this->workspace->id, 'name' => 'Pat', 'email' => 'pat@outside.example', 'status' => TrustedContactStatus::Active,
        'verified_at' => now()->subDays(9), 'active_from' => now()->subDays(6), 'created_by' => $this->anna->id,
    ]);
    $make = fn (int $hoursAgo, int $subject): TrustedConfirmation => TrustedConfirmation::query()->create([
        'workspace_id' => $this->workspace->id, 'contact_id' => $contact->id, 'subject_type' => 'payout_request', 'subject_id' => $subject,
        'token_hash' => hash('sha256', 'token-'.$subject), 'snapshot' => ['number' => 'PO-X'], 'status' => 'pending',
        'expires_at' => now()->subHours($hoursAgo)->addHours(24), 'created_by' => $this->mark->id, 'created_at' => now()->subHours($hoursAgo),
    ]);
    $reminded = $make(13, 1001);
    $expired = $make(25, 1002);

    $result = $this->internal('POST', '/internal/v1/payflow/payouts/escalations/run', [])->assertOk()->json();

    expect($result['confirmations_reminded'])->toBe(1)
        ->and($result['confirmations_expired'])->toBe(1)
        ->and($reminded->refresh()->reminded_at)->not->toBeNull()
        ->and($expired->refresh()->status->value)->toBe('expired');
});

it('serves bank changes and pending payouts to the assistant without holder names', function (): void {
    $this->actingAs($this->mark)->post(route('vendors.bank-changes.store', $this->vendor), [
        ...Guard::iban('PF Studio Payments UAB', 'LT583250067741200519', 'USD'), 'source' => 'vendor_email', 'requester_contact' => 'ivan@smirnov-dev.example',
    ]);
    $query = 'workspace_id='.$this->workspace->id.'&user_id='.$this->dmitry->id.'&locale=ru';
    $response = $this->internal('GET', '/internal/v1/payflow/ai/vendors/bank-changes?'.$query.'&vendor_query=ivan', null, 'ai')->assertOk();
    $item = $response->json('data.items.0');

    expect($response->json('resolved.name'))->toBe('Ivan Smirnov')
        ->and($item['status'])->toBe('pending_verification')
        ->and($item['after'])->toBe(['bank_country' => 'LT', 'currency' => 'USD', 'last4' => '0519', 'holder_matches_vendor' => false, 'holder_similarity' => 'mismatch'])
        ->and($item['before']['holder_similarity'])->toBe('match')
        ->and($item['affected_payouts'])->toBe([$this->payout->number])
        ->and($response->getContent())->not->toContain('PF Studio Payments')
        ->and($response->getContent())->not->toContain('LT583250067741200519')
        ->and($response->json('summary'))->toContain('Ivan Smirnov');

    $this->internal('GET', '/internal/v1/payflow/ai/payouts/pending?'.$query, null, 'ai')->assertOk()
        ->assertJsonPath('data.items', []);

    $stranger = Guard::member(Guard::workspace(Workspace::factory()->create()), WorkspaceRole::Owner);
    $this->internal('GET', '/internal/v1/payflow/ai/vendors/bank-changes?workspace_id='.$this->workspace->id.'&user_id='.$stranger->id.'&locale=en', null, 'ai')->assertForbidden();
    $this->internal('GET', '/internal/v1/payflow/ai/vendors/bank-changes?'.$query, null, 'worker')->assertForbidden();
});

it('lists pending payouts for the assistant with the viewer state', function (): void {
    $query = 'workspace_id='.$this->workspace->id.'&user_id='.$this->dmitry->id.'&locale=en';

    $this->internal('GET', '/internal/v1/payflow/ai/payouts/pending?'.$query, null, 'ai')->assertOk()
        ->assertJsonPath('data.items.0.number', $this->payout->number)
        ->assertJsonPath('data.items.0.awaiting_user', true)
        ->assertJsonPath('data.items.0.amount.minor', 72000)
        ->assertJsonPath('summary', '1 payout is waiting for approval.');

    expect(Vendor::query()->count())->toBe(1)->and(PayoutRequest::query()->count())->toBe(1);
});
