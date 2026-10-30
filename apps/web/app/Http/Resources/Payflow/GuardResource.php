<?php

declare(strict_types=1);

namespace App\Http\Resources\Payflow;

use App\Audit\Auditor;
use App\Domain\Analytics\GuardSummary;
use App\Domain\Guard\Bank\BankDetails;
use App\Domain\Guard\Bank\BankRules;
use App\Domain\Guard\Eligibility\EligibleMember;
use App\Domain\Guard\Enums\BankAccountStatus;
use App\Domain\Guard\Enums\BankScheme;
use App\Domain\Guard\Enums\PayoutStatus;
use App\Domain\Guard\Enums\VendorStatus;
use App\Domain\Guard\Models\PayoutApproval;
use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\TrustedConfirmation;
use App\Domain\Guard\Models\TrustedContact;
use App\Domain\Guard\Models\Vendor;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Guard\Payouts\PayoutEvaluator;
use App\Domain\Guard\Payouts\PayoutService;
use App\Domain\Privacy\PayflowExportContributor;
use App\Domain\Shared\Models\PayflowSettings;
use App\Domain\Shared\Money\MoneyData;
use App\Domain\Shared\Support\Iso;
use App\Domain\Shared\Support\UserRef;
use App\Domain\Shared\Time\Clock;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;

final class GuardResource
{
    /** @return array{id: int, name: string, status: string} */
    public static function vendorRef(Vendor $vendor): array
    {
        return ['id' => $vendor->id, 'name' => $vendor->name, 'status' => $vendor->status->value];
    }

    public static function display(VendorBankAccount $account): string
    {
        if ($account->scheme === BankScheme::Iban && $account->iban !== null && BankRules::ibanValid($account->iban)) {
            return BankRules::maskIban($account->iban);
        }

        return BankDetails::displayFor($account->scheme, $account->last4);
    }

    /** @return array<string, mixed> */
    public static function bankAccount(VendorBankAccount $account): array
    {
        return [
            'id' => $account->id,
            'version' => $account->version,
            'scheme' => $account->scheme->value,
            'holder_name' => $account->account_holder_name,
            'bank_name' => $account->bank_name,
            'bank_country' => $account->bank_country,
            'currency' => $account->currency,
            'last4' => $account->last4,
            'display' => self::display($account),
            'swift_bic_masked' => BankRules::maskSwift($account->swift_bic),
            'status' => $account->status->value,
            'source' => $account->source->value,
            'requester_email_domain' => $account->requester_email_domain,
            'risk_score' => $account->risk_score,
            'risk_band' => $account->risk_band->value,
            'risk_signals' => $account->risk_signals,
            'requested_by' => UserRef::of($account->requester),
            'requested_at' => Iso::dateTime($account->requested_at),
            'verified_by' => UserRef::of($account->verifier),
            'verified_at' => Iso::dateTime($account->verified_at),
            'verification_method' => $account->verification_method?->value,
            'verification_mode' => $account->verification_mode->value,
            'verification_note' => $account->verification_note,
            'cooling_off_until' => Iso::dateTime($account->cooling_off_until),
            'rejected_by' => UserRef::of($account->rejecter),
            'rejected_at' => Iso::dateTime($account->rejected_at),
            'rejection_reason' => $account->rejection_reason,
            'superseded_at' => Iso::dateTime($account->superseded_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function vendorListItem(Vendor $vendor): array
    {
        $current = $vendor->current_bank_account_id !== null
            ? VendorBankAccount::query()->forWorkspace($vendor->workspace_id)->find($vendor->current_bank_account_id)
            : null;
        $pending = VendorBankAccount::query()->forWorkspace($vendor->workspace_id)
            ->where('vendor_id', $vendor->id)
            ->where('status', BankAccountStatus::PendingVerification->value)
            ->first();
        $payouts = PayoutRequest::query()->forWorkspace($vendor->workspace_id)->where('vendor_id', $vendor->id);

        return [
            ...self::vendorRef($vendor),
            'legal_name' => $vendor->legal_name,
            'category' => $vendor->category->value,
            'country' => $vendor->country,
            'default_currency' => $vendor->default_currency,
            'current_account' => $current !== null ? [
                'id' => $current->id,
                'display' => self::display($current),
                'bank_country' => $current->bank_country,
                'currency' => $current->currency,
                'status' => $current->status->value,
                'cooling_off_until' => Iso::dateTime($current->cooling_off_until),
            ] : null,
            'pending_change' => $pending !== null ? [
                'id' => $pending->id,
                'risk_band' => $pending->risk_band->value,
                'risk_score' => $pending->risk_score,
                'requested_at' => Iso::dateTime($pending->requested_at),
            ] : null,
            'open_payouts_count' => (clone $payouts)->whereIn('status', ['draft', 'pending_approval', 'approved', 'on_hold'])->count(),
            'last_released_at' => Iso::dateTime(self::instant((clone $payouts)->where('status', PayoutStatus::Released->value)->max('released_at'))),
        ];
    }

    /** @return array<string, mixed> */
    public static function vendorDetail(Vendor $vendor): array
    {
        return [
            ...self::vendorListItem($vendor),
            'email' => $vendor->email,
            'email_domain' => $vendor->email_domain,
            'phone' => $vendor->phone,
            'phone_verified_at' => Iso::dateTime($vendor->phone_verified_at),
            'tax_id_masked' => BankRules::maskTaxId($vendor->tax_id),
            'notes' => $vendor->notes,
            'hold_reason' => $vendor->hold_reason,
            'anonymised_at' => Iso::dateTime($vendor->anonymised_at),
            'created_by' => UserRef::of($vendor->creator),
            'created_at' => Iso::dateTime($vendor->created_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function payoutListItem(PayoutRequest $payout, User $viewer, WorkspaceRole $role): array
    {
        return GuardSummary::payoutListItem($payout, $viewer, $role);
    }

    /** @return array<string, mixed> */
    public static function approvalRow(PayoutApproval $approval): array
    {
        $contact = null;

        if ($approval->trusted_confirmation_id !== null) {
            $confirmation = TrustedConfirmation::query()->forWorkspace($approval->workspace_id)->with('contact')->find($approval->trusted_confirmation_id);
            $contact = $confirmation !== null ? ['name' => $confirmation->contact->name] : null;
        }

        return [
            'id' => $approval->id,
            'user' => UserRef::of($approval->user),
            'trusted_contact' => $contact,
            'decision' => $approval->decision->value,
            'channel' => $approval->channel->value,
            'comment' => $approval->comment,
            'risk_band' => $approval->risk_band->value,
            'approval_round' => $approval->approval_round,
            'step_up_method' => $approval->step_up_method?->value,
            'created_at' => Iso::dateTime($approval->created_at),
            'invalidated_at' => Iso::dateTime($approval->invalidated_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function eligibleApprover(EligibleMember $member, ?PayoutRequest $payout = null): array
    {
        $decided = $payout !== null && PayoutApproval::query()->forWorkspace($payout->workspace_id)
            ->where('payout_request_id', $payout->id)
            ->where('approval_round', $payout->approval_round)
            ->where('user_id', $member->user->id)
            ->whereNull('invalidated_at')
            ->exists();

        return [
            'id' => $member->user->id,
            'name' => UserRef::name($member->user),
            'role' => $member->role->value,
            'decided' => $decided,
            'eligible' => $member->eligible(),
            'eligible_from' => Iso::dateTime($member->eligibleFrom),
            'missing_second_factor' => $member->missingSecondFactor,
        ];
    }

    /** @return array<string, mixed>|null */
    public static function trustedContact(?TrustedContact $contact): ?array
    {
        if ($contact === null) {
            return null;
        }

        return [
            'id' => $contact->id,
            'name' => $contact->name,
            'email_masked' => PayflowExportContributor::maskEmail($contact->email),
            'locale' => $contact->locale,
            'status' => $contact->status->value,
            'verified_at' => Iso::dateTime($contact->verified_at),
            'active_from' => Iso::dateTime($contact->active_from),
            'created_by' => UserRef::of($contact->creator),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function confirmationInfo(?TrustedConfirmation $confirmation): ?array
    {
        if ($confirmation === null) {
            return null;
        }

        $status = $confirmation->status->value;

        if ($status === 'pending' && $confirmation->expires_at->lessThanOrEqualTo(app(Clock::class)->now())) {
            $status = 'expired';
        }

        return [
            'id' => $confirmation->id,
            'contact_name' => $confirmation->contact->name,
            'status' => $status,
            'expires_at' => Iso::dateTime($confirmation->expires_at),
            'decided_at' => Iso::dateTime($confirmation->decided_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function guardSettings(PayflowSettings $settings): array
    {
        $thresholds = [];

        foreach ($settings->approval_thresholds ?? [] as $currency => $row) {
            $threshold = PayoutEvaluator::threshold($settings->approval_thresholds, (string) $currency);

            if ($threshold === null) {
                continue;
            }

            $thresholds[] = [
                'currency' => (string) $currency,
                'auto_approve_below' => MoneyData::of($threshold['auto_approve_below_minor'], (string) $currency),
                'dual_approval_from' => MoneyData::of($threshold['dual_approval_from_minor'], (string) $currency),
                'auto_approve_cumulative' => $threshold['auto_approve_cumulative_minor'] !== null ? MoneyData::of($threshold['auto_approve_cumulative_minor'], (string) $currency) : null,
            ];
        }

        return [
            'thresholds' => $thresholds,
            'cooling_off_hours_medium' => $settings->cooling_off_hours_medium,
            'cooling_off_hours_high' => $settings->cooling_off_hours_high,
            'allow_telegram_approvals' => $settings->allow_telegram_approvals,
            'escalate_pending_after_hours' => $settings->escalate_pending_after_hours,
            'guard_trust_delay_hours' => $settings->guard_trust_delay_hours,
            'require_second_factor' => $settings->require_second_factor,
            'solo_release_delay_hours' => $settings->solo_release_delay_hours,
        ];
    }

    /** @return list<array{code: string, ok: bool, params: array<string, string|int>}> */
    public static function checks(PayoutRequest $payout, User $viewer, PayoutService $service): array
    {
        $now = app(Clock::class)->now();
        $vendor = $payout->vendor;
        $account = $payout->bankAccount;
        $current = $service->bindingStillValid($payout);

        return [
            ['code' => 'vendor_active', 'ok' => $vendor->status === VendorStatus::Active && ! $vendor->trashed(), 'params' => []],
            ['code' => 'details_verified', 'ok' => $account !== null && in_array($account->status, [BankAccountStatus::Verified], true), 'params' => []],
            ['code' => 'details_current', 'ok' => $current, 'params' => []],
            ['code' => 'not_in_cooling_off', 'ok' => $account === null || $account->cooling_off_until === null || $account->cooling_off_until->lessThanOrEqualTo($now), 'params' => $account?->cooling_off_until !== null ? ['until' => (string) Iso::dateTime($account->cooling_off_until)] : []],
            ['code' => 'no_duplicates', 'ok' => ! PayoutService::signal($payout, 'duplicate_suspected'), 'params' => []],
            ['code' => 'no_split', 'ok' => ! PayoutService::signal($payout, 'split_suspected'), 'params' => []],
            ['code' => 'approvals_complete', 'ok' => $payout->approvals_count >= $payout->required_approvals && $payout->status !== PayoutStatus::Draft, 'params' => ['count' => $payout->approvals_count, 'required' => $payout->required_approvals]],
            ['code' => 'release_delay_passed', 'ok' => $payout->release_not_before === null || $payout->release_not_before->lessThanOrEqualTo($now), 'params' => $payout->release_not_before !== null ? ['not_before' => (string) Iso::dateTime($payout->release_not_before)] : []],
            ['code' => 'releaser_differs', 'ok' => ! $payout->release_requires_other_user || $viewer->id !== $payout->requested_by, 'params' => []],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function auditTimeline(PayoutRequest $payout): array
    {
        return array_values(AuditLog::query()
            ->with('actor')
            ->where('workspace_id', $payout->workspace_id)
            ->where('subject_type', Auditor::subjectType($payout))
            ->where('subject_id', (string) $payout->id)
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(function (AuditLog $entry): array {
                $data = [];

                foreach ((array) $entry->metadata as $key => $value) {
                    if (is_scalar($value) || $value === null) {
                        $data[(string) $key] = $value;
                    } elseif (is_array($value)) {
                        $data[(string) $key] = implode(', ', array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value));
                    }
                }

                return [
                    'id' => $entry->id,
                    'type' => str_replace('payflow.payout.', '', $entry->action),
                    'actor' => ['type' => $entry->actor_id !== null ? 'user' : 'system', 'user' => UserRef::of($entry->actor)],
                    'data' => $data,
                    'occurred_at' => Iso::dateTime($entry->created_at),
                ];
            })
            ->all());
    }

    private static function instant(mixed $value): ?CarbonImmutable
    {
        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}
