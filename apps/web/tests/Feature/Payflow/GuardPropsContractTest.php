<?php

declare(strict_types=1);

use App\Domain\Guard\Enums\TrustedContactStatus;
use App\Domain\Guard\Models\TrustedContact;
use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Payflow\Support\Guard;
use Tests\Feature\Payflow\Support\Payflow;

beforeEach(function (): void {
    Payflow::freeze('2026-10-22T10:00:00Z');
    $this->workspace = Guard::workspace(Workspace::factory()->create(['timezone' => 'Europe/Riga']));
    $this->anna = Guard::member($this->workspace, WorkspaceRole::Owner);
    $this->mark = Guard::member($this->workspace, WorkspaceRole::Finance);
    $this->dmitry = Guard::member($this->workspace, WorkspaceRole::Approver);
    $this->vendor = Guard::vendor($this->workspace, $this->mark, ['name' => 'Ivan Smirnov', 'legal_name' => 'IE Ivan Smirnov', 'country' => 'GE', 'default_currency' => 'USD', 'tax_id' => 'GE-404040404']);
    $this->account = Guard::verifiedAccount($this->vendor, $this->mark, $this->dmitry, Guard::iban('Ivan Smirnov', 'GE44TB7741936582010047', 'USD'));
    $this->payout = Guard::payout($this->workspace, $this->mark, $this->vendor->refresh(), 720000, 'USD');
});

function pageProps(TestResponse $response): array
{
    $page = $response->viewData('page');

    return is_array($page) ? $page['props'] : [];
}

function keysOf(array $value): array
{
    $keys = array_keys($value);
    sort($keys);

    return $keys;
}

function sortedKeys(string ...$keys): array
{
    sort($keys);

    return $keys;
}

it('renders the vendor edit form with the VendorDetail shape and masked tax id', function (): void {
    $response = $this->actingAs($this->mark)->get(route('vendors.edit', $this->vendor))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('vendors/Edit')->has('categories', 5)->has('countries')->has('currencies')->has('limits.vendors'));
    $vendor = pageProps($response)['vendor'];

    expect(keysOf($vendor))->toBe(sortedKeys(
        'id', 'name', 'status', 'legal_name', 'category', 'country', 'default_currency', 'current_account', 'pending_change', 'open_payouts_count', 'last_released_at',
        'email', 'email_domain', 'phone', 'phone_verified_at', 'tax_id_masked', 'notes', 'hold_reason', 'anonymised_at', 'created_by', 'created_at',
    ))
        ->and(keysOf($vendor['current_account']))->toBe(sortedKeys('id', 'display', 'bank_country', 'currency', 'status', 'cooling_off_until'))
        ->and($vendor['tax_id_masked'])->not->toBe('GE-404040404')
        ->and($vendor['open_payouts_count'])->toBe(1)
        ->and(json_encode(pageProps($response)))->not->toContain('GE44TB7741936582010047');

    $this->actingAs($this->dmitry)->get(route('vendors.edit', $this->vendor))->assertForbidden();
});

it('renders bank account versions and payout details in the contract shape', function (): void {
    $vendorProps = pageProps($this->actingAs($this->mark)->get(route('vendors.show', $this->vendor))->assertOk());
    $account = $vendorProps['accounts'][0];

    expect(keysOf($account))->toBe(sortedKeys(
        'id', 'version', 'scheme', 'holder_name', 'bank_name', 'bank_country', 'currency', 'last4', 'display', 'swift_bic_masked', 'status', 'source',
        'requester_email_domain', 'risk_score', 'risk_band', 'risk_signals', 'requested_by', 'requested_at', 'verified_by', 'verified_at',
        'verification_method', 'verification_mode', 'verification_note', 'cooling_off_until', 'rejected_by', 'rejected_at', 'rejection_reason', 'superseded_at',
    ))
        ->and($account['display'])->toContain('0047')
        ->and($account['display'])->not->toContain('7741936582')
        ->and(keysOf($vendorProps['can']))->toContain('edit', 'hold', 'request_change', 'reveal', 'create_payout', 'anonymise');

    $props = pageProps($this->actingAs($this->dmitry)->get(route('payouts.show', $this->payout))->assertOk());
    $payout = $props['payout'];

    expect(keysOf($payout))->toBe(sortedKeys(
        'id', 'number', 'vendor', 'amount', 'description', 'vendor_invoice_number', 'due_date', 'status', 'hold_reason', 'risk', 'required_approvals',
        'approvals_count', 'auto_approved', 'requested_by', 'submitted_at', 'approved_at', 'released_at', 'created_at', 'awaiting_me',
        'bank_account', 'current_account_matches', 'risk_signals', 'telegram_allowed', 'approval_round', 'approvals', 'eligible_approvers',
        'trusted_confirmation', 'release_requires_other_user', 'release_not_before', 'split_group_total', 'checks', 'paid_reference', 'paid_on',
        'released_by', 'rejected_at', 'canceled_at', 'canceled_by', 'cancel_reason', 'timeline',
    ))
        ->and(keysOf($payout['amount']))->toBe(sortedKeys('minor', 'currency'))
        ->and($payout['awaiting_me'])->toBeTrue()
        ->and(keysOf($payout['eligible_approvers'][0]))->toBe(sortedKeys('id', 'name', 'role', 'decided', 'eligible', 'eligible_from', 'missing_second_factor'))
        ->and(keysOf($props['can']))->toContain('edit', 'submit', 'resubmit', 'approve', 'reject', 'release', 'cancel')
        ->and($props['cannot_approve_reason'])->toBeNull()
        ->and($props['cannot_release_reason'])->toBe('not_approved')
        ->and($props['disclaimer_url'])->toContain('payment-guard-disclaimer');

    foreach ($payout['checks'] as $check) {
        expect(keysOf($check))->toBe(sortedKeys('code', 'ok', 'params'))
            ->and($check['code'])->toBeIn(['vendor_active', 'details_verified', 'details_current', 'not_in_cooling_off', 'no_duplicates', 'no_split', 'approvals_complete', 'release_delay_passed', 'releaser_differs']);
    }

    $mine = pageProps($this->actingAs($this->mark)->get(route('payouts.show', $this->payout)));

    expect($mine['cannot_approve_reason'])->toBe('no_permission')
        ->and($mine['payout']['awaiting_me'])->toBeFalse();
});

it('renders Guard settings, alerts and the overview rules card in the contract shape', function (): void {
    TrustedContact::query()->create([
        'workspace_id' => $this->workspace->id, 'name' => 'Lena', 'email' => 'lena@accounting.example', 'locale' => 'en',
        'status' => TrustedContactStatus::Active, 'verified_at' => now(), 'active_from' => now(), 'created_by' => $this->anna->id,
    ]);
    $settings = pageProps($this->actingAs($this->anna)->get(route('settings.guard'))->assertOk());

    expect(keysOf($settings))->toContain('settings', 'eligible_approvers', 'trusted_contact', 'currencies', 'can_edit')
        ->and(keysOf($settings['settings']))->toBe(sortedKeys(
            'thresholds', 'cooling_off_hours_medium', 'cooling_off_hours_high', 'allow_telegram_approvals', 'escalate_pending_after_hours',
            'guard_trust_delay_hours', 'require_second_factor', 'solo_release_delay_hours',
        ))
        ->and(keysOf($settings['settings']['thresholds'][0]))->toBe(sortedKeys('currency', 'auto_approve_below', 'dual_approval_from', 'auto_approve_cumulative'))
        ->and(keysOf($settings['trusted_contact']))->toBe(sortedKeys('id', 'name', 'email_masked', 'locale', 'status', 'verified_at', 'active_from', 'created_by'))
        ->and($settings['trusted_contact']['email_masked'])->toBe('l•••@accounting.example')
        ->and($settings['can_edit'])->toBeTrue();

    $index = pageProps($this->actingAs($this->dmitry)->get(route('guard.index'))->assertOk());
    $alert = $index['alerts']['data'][0];

    expect(keysOf($index['kpis']))->toBe(sortedKeys('awaiting_me', 'pending_approvals', 'bank_changes_pending', 'bank_changes_high', 'on_hold', 'released_this_month'))
        ->and($index['kpis']['awaiting_me'])->toBe(1)
        ->and(keysOf($alert))->toBe(sortedKeys('id', 'type', 'severity', 'status', 'subject', 'data', 'created_at', 'acknowledged_by'))
        ->and(keysOf($alert['subject']))->toBe(sortedKeys('type', 'id', 'label', 'url'))
        ->and(keysOf($index['me']))->toBe(sortedKeys('privileged', 'eligible_from', 'missing_second_factor'))
        ->and($index['rules']['solo_mode'])->toBeFalse()
        ->and($index['rules']['trusted_contact']['name'])->toBe('Lena');
});
