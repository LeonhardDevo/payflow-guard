<?php

declare(strict_types=1);

namespace App\Domain\Guard\Payouts;

use App\Domain\Guard\Approvals\ApprovalInput;
use App\Domain\Guard\Approvals\ApprovalRequirement;
use App\Domain\Guard\Eligibility\GuardEligibility;
use App\Domain\Guard\Enums\BankAccountStatus;
use App\Domain\Guard\Enums\PayoutStatus;
use App\Domain\Guard\Enums\TrustedContactStatus;
use App\Domain\Guard\Enums\VendorStatus;
use App\Domain\Guard\GuardException;
use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\TrustedContact;
use App\Domain\Guard\Models\Vendor;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Guard\Risk\PayoutRiskInput;
use App\Domain\Guard\Risk\PayoutRiskScorer;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Support\Iso;
use App\Domain\Shared\Time\BusinessDate;
use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

final readonly class PayoutEvaluator
{
    public const array FINAL_STATUSES = ['canceled', 'rejected'];

    public function __construct(
        private Clock $clock,
        private GuardEligibility $eligibility,
    ) {}

    public function blockingReason(PayoutRequest $payout, Vendor $vendor): ?string
    {
        if ($vendor->status !== VendorStatus::Active || $vendor->trashed()) {
            return 'vendor_on_hold';
        }

        $account = $this->currentAccount($vendor);

        if ($account === null) {
            return 'bank_details_unverified';
        }

        if ($this->hasPendingChange($vendor)) {
            return 'bank_change_pending';
        }

        if ($account->currency !== $payout->currency) {
            return 'currency_mismatch';
        }

        return null;
    }

    public function evaluate(PayoutRequest $payout, Vendor $vendor): PayoutEvaluation
    {
        $reason = $this->blockingReason($payout, $vendor);

        if ($reason !== null) {
            throw new GuardException($reason, 422);
        }

        $account = $this->currentAccount($vendor);
        assert($account instanceof VendorBankAccount);

        $workspace = Workspace::query()->findOrFail($payout->workspace_id);
        $settings = WorkspaceProvisioner::settingsFor($workspace);
        $now = $this->clock->now();
        $today = WorkspaceClock::todayIn($workspace->timezone, $now)->toDateString();
        $threshold = self::threshold($settings->approval_thresholds, $payout->currency);
        $coolingOff = $account->cooling_off_until !== null && $account->cooling_off_until->greaterThan($now);
        $others = fn () => PayoutRequest::query()->forWorkspace($payout->workspace_id)
            ->where('vendor_id', $vendor->id)
            ->where('id', '!=', (int) $payout->getKey());

        $releasedToAccount = PayoutRequest::query()->forWorkspace($payout->workspace_id)
            ->where('vendor_bank_account_id', $account->id)
            ->where('status', PayoutStatus::Released->value)
            ->count();
        $releasedAmounts = array_values(array_map('intval', $others()->where('currency', $payout->currency)
            ->where('status', PayoutStatus::Released->value)
            ->orderByDesc('released_at')
            ->limit(10)
            ->pluck('amount_minor')
            ->all()));
        $releasedCount = $others()->where('currency', $payout->currency)->where('status', PayoutStatus::Released->value)->count();
        $duplicate = $this->duplicateOf($payout, $vendor, $now);
        $recentAmounts = array_values(array_map('intval', $others()->where('currency', $payout->currency)
            ->whereNotIn('status', self::FINAL_STATUSES)
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', $now->subDays(7))
            ->pluck('amount_minor')
            ->all()));

        $risk = PayoutRiskScorer::score(new PayoutRiskInput(
            amountMinor: $payout->amount_minor,
            coolingOff: $coolingOff,
            coolingOffUntil: Iso::dateTime($account->cooling_off_until),
            accountChangeBand: $account->risk_band,
            accountChangeScore: $account->risk_score,
            verifiedDaysAgo: $account->verified_at !== null ? (int) floor($account->verified_at->diffInDays($now)) : null,
            releasedToAccount: $releasedToAccount,
            releasedAmounts: $releasedCount >= 3 ? $releasedAmounts : array_slice($releasedAmounts, 0, $releasedCount),
            duplicateOf: $duplicate,
            recentSubmittedAmounts: $recentAmounts,
            dualApprovalFromMinor: $threshold['dual_approval_from_minor'] ?? null,
            urgentDue: $payout->due_date !== null && $payout->due_date->toDateString() <= BusinessDate::addBusinessDays($today, 1),
            dueDate: Iso::date($payout->due_date),
        ));

        $recentChange = ($account->verified_at !== null && $account->verified_at->greaterThan($now->subDays(90)))
            || VendorBankAccount::query()->forWorkspace($payout->workspace_id)->where('vendor_id', $vendor->id)->where('requested_at', '>', $now->subDays(90))->exists()
            || $releasedToAccount === 0;
        $cumulative = (int) $others()->where('currency', $payout->currency)
            ->where('auto_approved', true)
            ->whereNotIn('status', self::FINAL_STATUSES)
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', $now->subDays(30))
            ->sum('amount_minor');
        $contact = $this->activeTrustedContact($payout->workspace_id, $now);
        $othersApprove = $this->eligibility->others($payout->workspace_id, 'payouts.approve', $payout->requested_by);

        $requirement = ApprovalRequirement::for(new ApprovalInput(
            amountMinor: $payout->amount_minor,
            threshold: $threshold,
            band: $risk->band,
            coolingOff: $coolingOff,
            recentChange: $recentChange,
            firstPayout: $risk->has('first_payout'),
            splitSuspected: $risk->has('split_suspected'),
            cumulative30Minor: $cumulative,
            othersApprove: $othersApprove,
            othersRelease: $this->eligibility->others($payout->workspace_id, 'payouts.release', $payout->requested_by),
            trustedContactActive: $contact !== null,
            allowTelegramApprovals: $settings->allow_telegram_approvals,
        ));

        $splitTotal = null;

        foreach ($risk->signals as $signal) {
            if ($signal['code'] === 'split_suspected') {
                $splitTotal = (int) $signal['params']['total_minor'];
            }
        }

        return new PayoutEvaluation($account, $risk, $requirement, $splitTotal, $othersApprove, $contact);
    }

    public function currentAccount(Vendor $vendor): ?VendorBankAccount
    {
        if ($vendor->current_bank_account_id === null) {
            return null;
        }

        $account = VendorBankAccount::query()->forWorkspace($vendor->workspace_id)->find($vendor->current_bank_account_id);

        return $account !== null && $account->status === BankAccountStatus::Verified ? $account : null;
    }

    public function hasPendingChange(Vendor $vendor): bool
    {
        return VendorBankAccount::query()->forWorkspace($vendor->workspace_id)
            ->where('vendor_id', $vendor->id)
            ->where('status', BankAccountStatus::PendingVerification->value)
            ->exists();
    }

    public function activeTrustedContact(int $workspaceId, CarbonImmutable $now): ?TrustedContact
    {
        $contact = TrustedContact::query()->forWorkspace($workspaceId)->where('status', TrustedContactStatus::Active->value)->first();

        return $contact !== null && $contact->isActiveAt($now) ? $contact : null;
    }

    /**
     * @param  array<string, array<string, int>>|null  $thresholds
     * @return array{auto_approve_below_minor: int, dual_approval_from_minor: int, auto_approve_cumulative_minor: int|null}|null
     */
    public static function threshold(?array $thresholds, string $currency): ?array
    {
        $row = $thresholds[$currency] ?? null;

        if (! is_array($row) || ! isset($row['auto_approve_below_minor'], $row['dual_approval_from_minor'])) {
            return null;
        }

        return [
            'auto_approve_below_minor' => (int) $row['auto_approve_below_minor'],
            'dual_approval_from_minor' => (int) $row['dual_approval_from_minor'],
            'auto_approve_cumulative_minor' => isset($row['auto_approve_cumulative_minor']) ? (int) $row['auto_approve_cumulative_minor'] : null,
        ];
    }

    private function duplicateOf(PayoutRequest $payout, Vendor $vendor, CarbonImmutable $now): ?string
    {
        $base = PayoutRequest::query()->forWorkspace($payout->workspace_id)
            ->where('vendor_id', $vendor->id)
            ->where('id', '!=', (int) $payout->getKey())
            ->whereNotIn('status', [...self::FINAL_STATUSES, PayoutStatus::Draft->value]);

        if ($payout->vendor_invoice_number !== null && $payout->vendor_invoice_number !== '') {
            $match = (clone $base)->whereRaw('lower(vendor_invoice_number) = ?', [mb_strtolower($payout->vendor_invoice_number)])->value('number');

            if (is_string($match)) {
                return $match;
            }
        }

        $match = (clone $base)->where('amount_minor', $payout->amount_minor)
            ->where('currency', $payout->currency)
            ->where('created_at', '>=', $now->subDays(30))
            ->value('number');

        return is_string($match) ? $match : null;
    }
}
