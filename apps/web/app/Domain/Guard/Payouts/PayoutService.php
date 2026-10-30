<?php

declare(strict_types=1);

namespace App\Domain\Guard\Payouts;

use App\Audit\Auditor;
use App\Billing\PlanLimits;
use App\Domain\Analytics\Enums\RiskBand;
use App\Domain\Guard\Alerts\GuardAlerts;
use App\Domain\Guard\Eligibility\GuardEligibility;
use App\Domain\Guard\Enums\AlertSubjectType;
use App\Domain\Guard\Enums\AlertType;
use App\Domain\Guard\Enums\ApprovalChannel;
use App\Domain\Guard\Enums\ApprovalDecision;
use App\Domain\Guard\Enums\PayoutHoldReason;
use App\Domain\Guard\Enums\PayoutStatus;
use App\Domain\Guard\Enums\TrustedConfirmationStatus;
use App\Domain\Guard\Enums\TrustedSubjectType;
use App\Domain\Guard\Enums\VendorStatus;
use App\Domain\Guard\GuardException;
use App\Domain\Guard\Models\PayoutApproval;
use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\TrustedConfirmation;
use App\Domain\Guard\Models\Vendor;
use App\Domain\Guard\Trusted\TrustedConfirmations;
use App\Domain\Notifications\Routing\GuardNotifications;
use App\Domain\Shared\Models\PayflowSettings;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Support\UserRef;
use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

final readonly class PayoutService
{
    public const array OPEN_STATUSES = ['pending_approval', 'approved'];

    public function __construct(
        private Clock $clock,
        private Auditor $auditor,
        private GuardAlerts $alerts,
        private GuardEligibility $eligibility,
        private PayoutEvaluator $evaluator,
        private GuardNotifications $notifications,
        private TrustedConfirmations $confirmations,
        private PlanLimits $limits,
    ) {}

    /** @param  array{vendor_id: int, amount_minor: int, currency: string, description: string, vendor_invoice_number?: string|null, due_date?: string|null}  $data */
    public function createDraft(Workspace $workspace, User $actor, array $data): PayoutRequest
    {
        $vendor = $this->activeVendor($workspace, $data['vendor_id']);

        return DB::transaction(function () use ($workspace, $actor, $data, $vendor): PayoutRequest {
            $payout = new PayoutRequest;
            $payout->forceFill([
                'workspace_id' => $workspace->id,
                'number' => $this->nextNumber($workspace),
                'vendor_id' => $vendor->id,
                'amount_minor' => $data['amount_minor'],
                'currency' => strtoupper($data['currency']),
                'description' => $data['description'],
                'vendor_invoice_number' => $data['vendor_invoice_number'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'status' => PayoutStatus::Draft,
                'requested_by' => $actor->id,
            ])->save();

            $this->auditor->record('payflow.payout.created', $payout, metadata: [
                'amount_minor' => $payout->amount_minor,
                'currency' => $payout->currency,
                'vendor_id' => $vendor->id,
            ], actor: $actor, workspace: $workspace, subjectLabel: $payout->number);

            return $payout->refresh();
        });
    }

    /** @param  array{vendor_id: int, amount_minor: int, currency: string, description: string, vendor_invoice_number?: string|null, due_date?: string|null}  $data */
    public function updateDraft(PayoutRequest $payout, User $actor, array $data): PayoutRequest
    {
        if ($payout->status !== PayoutStatus::Draft) {
            throw new GuardException('payout_immutable');
        }

        $vendor = $this->activeVendor(Workspace::query()->findOrFail($payout->workspace_id), $data['vendor_id']);
        $before = $payout->only(['vendor_id', 'amount_minor', 'currency', 'description', 'vendor_invoice_number']);
        $payout->forceFill([
            'vendor_id' => $vendor->id,
            'amount_minor' => $data['amount_minor'],
            'currency' => strtoupper($data['currency']),
            'description' => $data['description'],
            'vendor_invoice_number' => $data['vendor_invoice_number'] ?? null,
            'due_date' => $data['due_date'] ?? null,
        ])->save();
        $this->auditor->record('payflow.payout.created', $payout, Auditor::diff($before, $payout->only(array_keys($before))), ['updated' => true], actor: $actor, workspace: $payout->workspace_id, subjectLabel: $payout->number);

        return $payout;
    }

    public function submit(PayoutRequest $payout, User $actor): PayoutRequest
    {
        if ($payout->status !== PayoutStatus::Draft) {
            throw new GuardException('invalid_payout_transition');
        }

        $workspace = Workspace::query()->findOrFail($payout->workspace_id);
        $this->limits->ensure($workspace, 'payouts_per_month');

        return $this->evaluateAndOpen($payout, $actor, 'payflow.payout.submitted');
    }

    public function resubmit(PayoutRequest $payout, User $actor): PayoutRequest
    {
        if ($payout->status !== PayoutStatus::OnHold) {
            throw new GuardException('invalid_payout_transition');
        }

        $reason = $this->evaluator->blockingReason($payout, $payout->vendor);

        if ($reason !== null) {
            throw new GuardException('hold_not_cleared', 409, ['reason' => (string) __('payflow.hold_reasons.'.$reason)], ['reason' => $reason]);
        }

        $this->alerts->resolve($payout->workspace_id, 'po-hold:'.$payout->id, $actor);

        return $this->evaluateAndOpen($payout, $actor, 'payflow.payout.resubmitted', newRound: true);
    }

    public function approve(PayoutRequest $payout, User $approver, DecisionContext $context, ?string $comment = null, ?string $fp8 = null): PayoutRequest
    {
        $this->holdIfDetailsChanged($payout, PayoutStatus::PendingApproval);

        return DB::transaction(function () use ($payout, $approver, $context, $comment, $fp8): PayoutRequest {
            $payout = $this->lock($payout);
            $this->assertCanDecide($payout, $approver, $context, $fp8);

            $approval = $this->recordDecision($payout, ApprovalDecision::Approved, $context, $approver->id, null, $comment);
            $payout->approvals_count++;
            $this->completeIfReached($payout);
            $payout->save();

            $this->auditor->record('payflow.payout.approved', $payout, metadata: [
                'channel' => $context->channel->value,
                'round' => $payout->approval_round,
                'approvals' => $payout->approvals_count.'/'.$payout->required_approvals,
                'step_up_method' => $context->stepUpMethod?->value,
            ], actor: $approver, workspace: $payout->workspace_id, subjectLabel: $payout->number);

            $this->notifications->payoutDecided($payout, $approval);

            return $payout;
        });
    }

    public function reject(PayoutRequest $payout, User $approver, DecisionContext $context, string $comment, ?string $fp8 = null): PayoutRequest
    {
        if (mb_strlen(trim($comment)) < 5) {
            throw new GuardException('comment_required', 422);
        }

        $this->holdIfDetailsChanged($payout, PayoutStatus::PendingApproval);

        return DB::transaction(function () use ($payout, $approver, $context, $comment, $fp8): PayoutRequest {
            $payout = $this->lock($payout);
            $this->assertCanDecide($payout, $approver, $context, $fp8, rejecting: true);

            $approval = $this->recordDecision($payout, ApprovalDecision::Rejected, $context, $approver->id, null, trim($comment));
            $this->markRejected($payout);
            $this->auditor->record('payflow.payout.rejected', $payout, metadata: ['channel' => $context->channel->value, 'comment' => trim($comment)], actor: $approver, workspace: $payout->workspace_id, subjectLabel: $payout->number);
            $this->notifications->payoutDecided($payout, $approval);

            return $payout;
        });
    }

    public function trustedDecision(PayoutRequest $payout, TrustedConfirmation $confirmation, bool $confirm, ?string $comment, ?string $ip, ?string $userAgent): PayoutRequest
    {
        return DB::transaction(function () use ($payout, $confirmation, $confirm, $comment, $ip, $userAgent): PayoutRequest {
            $payout = $this->lock($payout);

            if ($payout->status !== PayoutStatus::PendingApproval) {
                throw new GuardException('not_pending');
            }

            $context = new DecisionContext(ApprovalChannel::TrustedContact, null, $ip, $userAgent);
            $approval = $this->recordDecision($payout, $confirm ? ApprovalDecision::Approved : ApprovalDecision::Rejected, $context, null, $confirmation->id, $comment);
            $contactName = $confirmation->contact->name;

            if ($confirm) {
                $payout->approvals_count++;
                $this->completeIfReached($payout);
                $payout->save();
            } else {
                $this->markRejected($payout);
            }

            $this->auditor->record($confirm ? 'payflow.payout.approved' : 'payflow.payout.rejected', $payout, metadata: [
                'channel' => ApprovalChannel::TrustedContact->value,
                'contact' => $contactName,
                'round' => $payout->approval_round,
            ], workspace: $payout->workspace_id, subjectLabel: $payout->number, system: true);
            $this->notifications->payoutDecided($payout, $approval, $contactName);

            return $payout;
        });
    }

    /** @param  array{paid_reference: string, paid_on: string}  $data */
    public function release(PayoutRequest $payout, User $releaser, array $data): PayoutRequest
    {
        $denial = $this->releaseDenial($payout, $releaser);

        if ($denial !== null) {
            throw new GuardException($denial, $denial === 'no_permission' ? 403 : 409, [], $denial === 'release_delayed' ? ['not_before' => (string) $payout->release_not_before?->utc()->format('Y-m-d\TH:i:s\Z')] : []);
        }

        $this->holdIfDetailsChanged($payout, PayoutStatus::Approved);

        return DB::transaction(function () use ($payout, $releaser, $data): PayoutRequest {
            $payout = $this->lock($payout);

            if ($this->releaseDenial($payout, $releaser) !== null) {
                throw new GuardException('not_approved');
            }

            $payout->forceFill([
                'status' => PayoutStatus::Released,
                'released_by' => $releaser->id,
                'released_at' => $this->clock->now(),
                'paid_reference' => trim($data['paid_reference']),
                'paid_on' => $data['paid_on'],
            ])->save();

            $this->alerts->resolve($payout->workspace_id, 'po-high:'.$payout->id, $releaser);
            $this->auditor->record('payflow.payout.released', $payout, metadata: [
                'paid_reference' => $payout->paid_reference,
                'paid_on' => $data['paid_on'],
                'amount_minor' => $payout->amount_minor,
                'currency' => $payout->currency,
            ], actor: $releaser, workspace: $payout->workspace_id, subjectLabel: $payout->number);
            $this->notifications->payoutReleased($payout, $releaser);

            return $payout;
        });
    }

    public function cancel(PayoutRequest $payout, User $actor, string $reason): PayoutRequest
    {
        return DB::transaction(function () use ($payout, $actor, $reason): PayoutRequest {
            $payout = $this->lock($payout);

            if (! in_array($payout->status, [PayoutStatus::Draft, PayoutStatus::PendingApproval, PayoutStatus::Approved, PayoutStatus::OnHold], true)) {
                throw new GuardException('invalid_payout_transition');
            }

            $payout->forceFill([
                'status' => PayoutStatus::Canceled,
                'canceled_at' => $this->clock->now(),
                'canceled_by' => $actor->id,
                'cancel_reason' => trim($reason),
            ])->save();

            $this->closeOpenItems($payout, $actor);
            $this->auditor->record('payflow.payout.canceled', $payout, metadata: ['reason' => trim($reason)], actor: $actor, workspace: $payout->workspace_id, subjectLabel: $payout->number);

            return $payout;
        });
    }

    public function holdVendorPayouts(Vendor $vendor, PayoutHoldReason $reason, ?User $actor): int
    {
        $payouts = PayoutRequest::query()->forWorkspace($vendor->workspace_id)
            ->where('vendor_id', $vendor->id)
            ->whereIn('status', self::OPEN_STATUSES)
            ->lockForUpdate()
            ->get();

        foreach ($payouts as $payout) {
            $this->hold($payout, $reason, $actor);
        }

        return $payouts->count();
    }

    public function restoreAfterRejectedChange(Vendor $vendor, User $actor): int
    {
        $payouts = PayoutRequest::query()->forWorkspace($vendor->workspace_id)
            ->where('vendor_id', $vendor->id)
            ->where('status', PayoutStatus::OnHold->value)
            ->where('hold_reason', PayoutHoldReason::BankDetailsChanged->value)
            ->get();
        $restored = 0;

        foreach ($payouts as $payout) {
            if ($this->evaluator->blockingReason($payout, $vendor) !== null || ! $this->bindingStillValid($payout)) {
                continue;
            }

            $payout->forceFill([
                'status' => PayoutStatus::PendingApproval,
                'hold_reason' => null,
                'approval_round' => $payout->approval_round + 1,
                'approvals_count' => 0,
                'approved_at' => null,
            ])->save();
            $this->alerts->resolve($payout->workspace_id, 'po-hold:'.$payout->id, $actor);
            $this->openRoundAlerts($payout);
            $this->auditor->record('payflow.payout.resubmitted', $payout, metadata: ['round' => $payout->approval_round, 'automatic' => true], actor: $actor, workspace: $payout->workspace_id, subjectLabel: $payout->number);
            $this->notifications->payoutPendingApproval($payout);
            $restored++;
        }

        return $restored;
    }

    public function invalidateForMember(int $workspaceId, User $member, ?User $actor): int
    {
        $approvals = PayoutApproval::query()->forWorkspace($workspaceId)
            ->where('user_id', $member->id)
            ->whereNull('invalidated_at')
            ->whereHas('payout', fn ($query) => $query->whereIn('status', self::OPEN_STATUSES)->whereColumn('payout_requests.approval_round', 'payout_approvals.approval_round'))
            ->get();

        foreach ($approvals as $approval) {
            $approval->forceFill(['invalidated_at' => $this->clock->now()])->save();
            $payout = PayoutRequest::query()->forWorkspace($workspaceId)->lockForUpdate()->findOrFail($approval->payout_request_id);

            if ($payout->status === PayoutStatus::Approved) {
                $payout->forceFill([
                    'status' => PayoutStatus::PendingApproval,
                    'approval_round' => $payout->approval_round + 1,
                    'approvals_count' => 0,
                    'approved_at' => null,
                    'release_not_before' => null,
                ])->save();
                $this->openRoundAlerts($payout);
            } else {
                $payout->forceFill(['approvals_count' => $this->validApprovals($payout)])->save();
            }

            $this->auditor->record('payflow.payout.approvals_invalidated', $payout, metadata: ['user_id' => $member->id, 'round' => $payout->approval_round], actor: $actor, workspace: $workspaceId, subjectLabel: $payout->number, system: $actor === null);
        }

        return $approvals->count();
    }

    public function approvalDenial(PayoutRequest $payout, User $user, bool $forTelegram = false): ?string
    {
        if ($payout->status !== PayoutStatus::PendingApproval) {
            return 'not_pending';
        }

        $member = $this->eligibility->find($payout->workspace_id, $user);

        if ($member === null || ! $member->role->allows('payouts.approve')) {
            return 'no_permission';
        }

        if ($payout->requested_by === $user->id) {
            return 'is_requester';
        }

        if (PayoutApproval::query()->forWorkspace($payout->workspace_id)->where('payout_request_id', $payout->id)
            ->where('approval_round', $payout->approval_round)->where('user_id', $user->id)->exists()) {
            return 'already_decided';
        }

        $reason = $member->reason();

        if ($reason !== null) {
            return $reason;
        }

        if (! $this->bindingStillValid($payout)) {
            return 'details_changed';
        }

        return null;
    }

    public function releaseDenial(PayoutRequest $payout, User $user): ?string
    {
        if ($payout->status !== PayoutStatus::Approved) {
            return 'not_approved';
        }

        $member = $this->eligibility->find($payout->workspace_id, $user);

        if ($member === null || ! $member->role->allows('payouts.release') || ! $member->eligible()) {
            return 'no_permission';
        }

        if ($payout->release_requires_other_user && $payout->requested_by === $user->id) {
            return 'releaser_is_requester';
        }

        if ($payout->release_not_before !== null && $payout->release_not_before->greaterThan($this->clock->now())) {
            return 'release_delayed';
        }

        return null;
    }

    public static function stepUpForApproval(PayoutRequest $payout): bool
    {
        return $payout->risk_band !== RiskBand::Low || self::signal($payout, 'cooling_off') || self::signal($payout, 'split_suspected');
    }

    public static function stepUpForRelease(PayoutRequest $payout): bool
    {
        return $payout->risk_band !== RiskBand::Low;
    }

    public static function telegramApprovalAllowed(PayoutRequest $payout): bool
    {
        return $payout->telegram_allowed && WorkspaceProvisioner::settingsFor($payout->workspace_id)->allow_telegram_approvals;
    }

    public static function signal(PayoutRequest $payout, string $code): bool
    {
        foreach ($payout->risk_signals ?? [] as $signal) {
            if ($signal['code'] === $code) {
                return true;
            }
        }

        return false;
    }

    public function bindingStillValid(PayoutRequest $payout): bool
    {
        $vendor = Vendor::withTrashed()->forWorkspace($payout->workspace_id)->find($payout->vendor_id);

        if ($vendor === null || $vendor->status !== VendorStatus::Active || $vendor->trashed()) {
            return false;
        }

        $account = $this->evaluator->currentAccount($vendor);

        return $account !== null && $payout->bank_fingerprint !== null && hash_equals($account->fingerprint, $payout->bank_fingerprint)
            && $account->id === $payout->vendor_bank_account_id;
    }

    private function evaluateAndOpen(PayoutRequest $payout, User $actor, string $auditAction, bool $newRound = false): PayoutRequest
    {
        $vendor = $payout->vendor;
        $evaluation = $this->evaluator->evaluate($payout, $vendor);
        $requirement = $evaluation->requirement;

        if ($requirement->blocked) {
            $this->alerts->ensure($payout->workspace_id, AlertType::InsufficientApprovers, AlertSubjectType::Workspace, $payout->workspace_id, 'ia:'.$payout->workspace_id, [
                'number' => $payout->number,
                'required' => $requirement->required,
                'eligible' => $evaluation->othersApprove,
            ]);
            $this->notifications->insufficientApprovers($payout, $requirement->required, $evaluation->othersApprove);

            throw new GuardException('insufficient_approvers', 422, ['required' => $requirement->required, 'eligible' => $evaluation->othersApprove], [
                'required' => $requirement->required,
                'eligible' => $evaluation->othersApprove,
                'trusted_contact' => $evaluation->trustedContact !== null,
            ]);
        }

        return DB::transaction(function () use ($payout, $actor, $auditAction, $newRound, $evaluation, $requirement): PayoutRequest {
            $expected = $payout->status;
            $payout = $this->lock($payout);

            if ($payout->status !== $expected) {
                throw new GuardException('invalid_payout_transition');
            }

            $now = $this->clock->now();
            $approved = $requirement->required === 0;

            $payout->forceFill([
                'vendor_bank_account_id' => $evaluation->account->id,
                'bank_fingerprint' => $evaluation->account->fingerprint,
                'risk_score' => $evaluation->risk->score,
                'risk_band' => $evaluation->risk->band,
                'risk_signals' => $evaluation->risk->signals,
                'required_approvals' => $requirement->required,
                'approvals_count' => 0,
                'approval_round' => $newRound ? $payout->approval_round + 1 : $payout->approval_round,
                'auto_approved' => $requirement->autoApproved,
                'auto_approve_basis' => $requirement->autoApproveBasis,
                'release_requires_other_user' => $requirement->releaseRequiresOtherUser,
                'release_not_before' => null,
                'split_group_total_minor' => $evaluation->splitTotal,
                'telegram_allowed' => $requirement->telegramAllowed,
                'status' => $approved ? PayoutStatus::Approved : PayoutStatus::PendingApproval,
                'hold_reason' => null,
                'submitted_at' => $payout->submitted_at ?? $now,
                'approved_at' => $approved ? $now : null,
            ])->save();

            $this->auditor->record($auditAction, $payout, metadata: [
                'risk_score' => $evaluation->risk->score,
                'risk_band' => $evaluation->risk->band->value,
                'signals' => $evaluation->risk->codes(),
                'required_approvals' => $requirement->required,
                'round' => $payout->approval_round,
            ], actor: $actor, workspace: $payout->workspace_id, subjectLabel: $payout->number);
            $this->alerts->resolve($payout->workspace_id, 'ia:'.$payout->workspace_id);

            if ($approved) {
                $this->auditor->record('payflow.payout.auto_approved', $payout, metadata: (array) $requirement->autoApproveBasis, actor: $actor, workspace: $payout->workspace_id, subjectLabel: $payout->number);

                return $payout;
            }

            $this->openRoundAlerts($payout);

            if ($evaluation->risk->band === RiskBand::High) {
                $this->alerts->ensure($payout->workspace_id, AlertType::PayoutHighRisk, AlertSubjectType::PayoutRequest, $payout->id, 'po-high:'.$payout->id, [
                    'number' => $payout->number,
                    'vendor' => $payout->vendor->name,
                    'score' => $evaluation->risk->score,
                ]);
            }

            if ($requirement->usesTrustedContact && $evaluation->trustedContact !== null) {
                $this->confirmations->create($evaluation->trustedContact, TrustedSubjectType::PayoutRequest, $payout->id, $this->snapshot($payout), $actor);
            }

            $this->notifications->payoutPendingApproval($payout);

            return $payout;
        });
    }

    /** @return array<string, string|int|float|null> */
    public function snapshot(PayoutRequest $payout): array
    {
        $payout->loadMissing(['vendor', 'requester', 'bankAccount']);

        return [
            'amount_minor' => $payout->amount_minor,
            'currency' => $payout->currency,
            'vendor' => $payout->vendor->name,
            'number' => $payout->number,
            'description' => $payout->description,
            'last4' => $payout->bankAccount?->last4,
            'fingerprint8' => substr((string) $payout->bank_fingerprint, 0, 8),
            'risk_band' => $payout->risk_band?->value,
            'risk_score' => $payout->risk_score,
            'signals' => implode(',', array_map(fn (array $signal): string => $signal['code'], $payout->risk_signals ?? [])),
            'requester' => UserRef::name($payout->requester),
        ];
    }

    private function assertCanDecide(PayoutRequest $payout, User $user, DecisionContext $context, ?string $fp8, bool $rejecting = false): void
    {
        $denial = $this->approvalDenial($payout, $user);

        if ($denial !== null) {
            throw new GuardException($denial, $denial === 'no_permission' ? 403 : 409);
        }

        if ($context->channel === ApprovalChannel::Telegram) {
            $expected = substr((string) $payout->bank_fingerprint, 0, 8);

            if ($fp8 === null || ! hash_equals($expected, $fp8)) {
                throw new GuardException('details_changed');
            }

            if (! $rejecting && ! self::telegramApprovalAllowed($payout)) {
                throw new GuardException('web_only');
            }
        }
    }

    private function holdIfDetailsChanged(PayoutRequest $payout, PayoutStatus $expected): void
    {
        if ($payout->status !== $expected || $this->bindingStillValid($payout)) {
            return;
        }

        $vendor = Vendor::withTrashed()->forWorkspace($payout->workspace_id)->find($payout->vendor_id);
        $reason = $vendor !== null && $vendor->status === VendorStatus::Active && ! $vendor->trashed()
            ? PayoutHoldReason::BankDetailsChanged
            : PayoutHoldReason::VendorOnHold;

        DB::transaction(fn () => $this->hold($this->lock($payout), $reason, null));

        throw new GuardException('details_changed');
    }

    private function recordDecision(PayoutRequest $payout, ApprovalDecision $decision, DecisionContext $context, ?int $userId, ?int $confirmationId, ?string $comment): PayoutApproval
    {
        $approval = new PayoutApproval;
        $approval->forceFill([
            'workspace_id' => $payout->workspace_id,
            'payout_request_id' => $payout->id,
            'user_id' => $userId,
            'decision' => $decision,
            'channel' => $context->channel,
            'trusted_confirmation_id' => $confirmationId,
            'comment' => $comment !== null && trim($comment) !== '' ? mb_substr(trim($comment), 0, 500) : null,
            'amount_minor' => $payout->amount_minor,
            'currency' => $payout->currency,
            'bank_fingerprint' => (string) $payout->bank_fingerprint,
            'risk_band' => $payout->risk_band ?? RiskBand::Low,
            'approval_round' => $payout->approval_round,
            'step_up_method' => $context->stepUpMethod,
            'ip' => $context->ip,
            'user_agent' => $context->userAgent,
            'created_at' => $this->clock->now(),
        ])->save();

        return $approval;
    }

    private function completeIfReached(PayoutRequest $payout): void
    {
        if ($payout->approvals_count < $payout->required_approvals) {
            return;
        }

        $now = $this->clock->now();
        $payout->status = PayoutStatus::Approved;
        $payout->approved_at = $now;

        $trusted = PayoutApproval::query()->forWorkspace($payout->workspace_id)
            ->where('payout_request_id', $payout->id)
            ->where('approval_round', $payout->approval_round)
            ->where('channel', ApprovalChannel::TrustedContact->value)
            ->where('decision', ApprovalDecision::Approved->value)
            ->exists();

        if ($trusted) {
            $settings = WorkspaceProvisioner::settingsFor($payout->workspace_id);
            $payout->release_not_before = $now->addHours($settings->solo_release_delay_hours);
        }

        $this->alerts->resolve($payout->workspace_id, 'po:'.$payout->id.':'.$payout->approval_round);
        $this->alerts->resolve($payout->workspace_id, 'po-stale:'.$payout->id);
    }

    private function markRejected(PayoutRequest $payout): void
    {
        $payout->forceFill(['status' => PayoutStatus::Rejected, 'rejected_at' => $this->clock->now()])->save();
        $this->closeOpenItems($payout, null);
    }

    private function closeOpenItems(PayoutRequest $payout, ?User $actor): void
    {
        foreach (['po:'.$payout->id.':'.$payout->approval_round, 'po-high:'.$payout->id, 'po-hold:'.$payout->id, 'po-stale:'.$payout->id] as $key) {
            $this->alerts->resolve($payout->workspace_id, $key, $actor);
        }

        TrustedConfirmation::query()->forWorkspace($payout->workspace_id)
            ->where('subject_type', TrustedSubjectType::PayoutRequest->value)
            ->where('subject_id', $payout->id)
            ->where('status', TrustedConfirmationStatus::Pending->value)
            ->update(['status' => TrustedConfirmationStatus::Canceled->value]);
    }

    private function hold(PayoutRequest $payout, PayoutHoldReason $reason, ?User $actor): void
    {
        $previous = $payout->status;
        PayoutApproval::query()->forWorkspace($payout->workspace_id)
            ->where('payout_request_id', $payout->id)
            ->where('approval_round', $payout->approval_round)
            ->whereNull('invalidated_at')
            ->update(['invalidated_at' => $this->clock->now()]);

        $payout->forceFill([
            'status' => PayoutStatus::OnHold,
            'hold_reason' => $reason,
            'approvals_count' => 0,
            'approved_at' => null,
        ])->save();

        $this->alerts->resolve($payout->workspace_id, 'po:'.$payout->id.':'.$payout->approval_round);
        $this->alerts->resolve($payout->workspace_id, 'po-stale:'.$payout->id);
        $this->alerts->ensure($payout->workspace_id, AlertType::PayoutOnHold, AlertSubjectType::PayoutRequest, $payout->id, 'po-hold:'.$payout->id, [
            'number' => $payout->number,
            'vendor' => $payout->vendor->name,
            'reason' => $reason->value,
        ]);
        TrustedConfirmation::query()->forWorkspace($payout->workspace_id)
            ->where('subject_type', TrustedSubjectType::PayoutRequest->value)
            ->where('subject_id', $payout->id)
            ->where('status', TrustedConfirmationStatus::Pending->value)
            ->update(['status' => TrustedConfirmationStatus::Canceled->value]);
        $this->auditor->record('payflow.payout.held', $payout, ['status' => [$previous->value, PayoutStatus::OnHold->value]], ['reason' => $reason->value], actor: $actor, workspace: $payout->workspace_id, subjectLabel: $payout->number, system: $actor === null);
    }

    private function openRoundAlerts(PayoutRequest $payout): void
    {
        $this->alerts->ensure($payout->workspace_id, AlertType::PayoutPendingApproval, AlertSubjectType::PayoutRequest, $payout->id, 'po:'.$payout->id.':'.$payout->approval_round, [
            'number' => $payout->number,
            'vendor' => $payout->vendor->name,
            'amount_minor' => $payout->amount_minor,
            'currency' => $payout->currency,
        ]);
    }

    private function validApprovals(PayoutRequest $payout): int
    {
        return PayoutApproval::query()->forWorkspace($payout->workspace_id)
            ->where('payout_request_id', $payout->id)
            ->where('approval_round', $payout->approval_round)
            ->where('decision', ApprovalDecision::Approved->value)
            ->whereNull('invalidated_at')
            ->count();
    }

    private function lock(PayoutRequest $payout): PayoutRequest
    {
        return PayoutRequest::query()->forWorkspace($payout->workspace_id)->lockForUpdate()->findOrFail($payout->id);
    }

    private function activeVendor(Workspace $workspace, int $vendorId): Vendor
    {
        $vendor = Vendor::query()->forWorkspace($workspace)->find($vendorId);

        if ($vendor === null || $vendor->status === VendorStatus::Archived) {
            throw new GuardException('vendor_not_found', 422);
        }

        return $vendor;
    }

    private function nextNumber(Workspace $workspace): string
    {
        WorkspaceProvisioner::settingsFor($workspace);
        $settings = PayflowSettings::query()->forWorkspace($workspace)->lockForUpdate()->firstOrFail();
        $year = WorkspaceClock::todayIn($workspace->timezone, $this->clock->now())->format('Y');

        do {
            $number = $settings->payout_prefix.$year.'-'.str_pad((string) $settings->next_payout_seq, 4, '0', STR_PAD_LEFT);
            $settings->next_payout_seq++;
        } while (PayoutRequest::query()->forWorkspace($workspace)->where('number', $number)->exists());

        $settings->save();

        return $number;
    }
}
