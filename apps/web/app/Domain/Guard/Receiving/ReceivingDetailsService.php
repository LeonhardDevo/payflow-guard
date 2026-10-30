<?php

declare(strict_types=1);

namespace App\Domain\Guard\Receiving;

use App\Audit\Auditor;
use App\Domain\Guard\Alerts\GuardAlerts;
use App\Domain\Guard\Bank\GuardKeys;
use App\Domain\Guard\Eligibility\EligibleMember;
use App\Domain\Guard\Eligibility\GuardEligibility;
use App\Domain\Guard\Enums\AlertSubjectType;
use App\Domain\Guard\Enums\AlertType;
use App\Domain\Guard\Enums\ConfirmationMode;
use App\Domain\Guard\Enums\ReceivingDetailsStatus;
use App\Domain\Guard\Enums\TrustedConfirmationStatus;
use App\Domain\Guard\Enums\TrustedSubjectType;
use App\Domain\Guard\GuardException;
use App\Domain\Guard\Models\ReceivingDetailsVersion;
use App\Domain\Guard\Models\TrustedConfirmation;
use App\Domain\Guard\Payouts\PayoutEvaluator;
use App\Domain\Guard\Trusted\TrustedConfirmations;
use App\Domain\Notifications\Routing\GuardNotifications;
use App\Domain\Shared\Models\PayflowSettings;
use App\Domain\Shared\Support\UserRef;
use App\Domain\Shared\Time\Clock;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class ReceivingDetailsService
{
    public const int DELAY_HOURS = 24;

    public function __construct(
        private Clock $clock,
        private Auditor $auditor,
        private GuardAlerts $alerts,
        private GuardEligibility $eligibility,
        private GuardNotifications $notifications,
        private PayoutEvaluator $evaluator,
        private TrustedConfirmations $confirmations,
    ) {}

    public function request(Workspace $workspace, User $requester, string $details): ReceivingDetailsVersion
    {
        $details = trim(str_replace("\r\n", "\n", $details));

        $version = DB::transaction(function () use ($workspace, $requester, $details): ReceivingDetailsVersion {
            $open = ReceivingDetailsVersion::query()->forWorkspace($workspace)
                ->whereIn('status', [ReceivingDetailsStatus::PendingConfirmation->value, ReceivingDetailsStatus::Scheduled->value])
                ->lockForUpdate()
                ->exists();

            if ($open) {
                throw new GuardException('receiving_details_pending');
            }

            $now = $this->clock->now();
            $version = new ReceivingDetailsVersion;
            $version->forceFill([
                'workspace_id' => $workspace->id,
                'version' => (int) ReceivingDetailsVersion::query()->forWorkspace($workspace)->max('version') + 1,
                'details' => $details,
                'details_hash' => GuardKeys::mac(GuardKeys::RECEIVING_DETAILS, $details),
                'status' => ReceivingDetailsStatus::PendingConfirmation,
                'requested_by' => $requester->id,
                'requested_at' => $now,
                'effective_at' => $now->addHours(self::DELAY_HOURS),
            ])->save();

            $this->auditor->record('payflow.receiving_details.requested', $version, metadata: ['version' => $version->version, 'last4' => self::last4($details)], actor: $requester, workspace: $workspace, subjectLabel: 'v'.$version->version);

            $mode = $this->confirmationMode($workspace, $requester);

            if ($mode === ConfirmationMode::TrustedContact) {
                $contact = $this->evaluator->activeTrustedContact($workspace->id, $now);

                if ($contact !== null) {
                    $version->forceFill(['confirmation_mode' => ConfirmationMode::TrustedContact])->save();
                    $this->confirmations->create($contact, TrustedSubjectType::ReceivingDetailsVersion, $version->id, [
                        'last4' => self::last4($details),
                        'requester' => UserRef::name($requester),
                        'version' => $version->version,
                    ], $requester);
                }
            } elseif ($mode === ConfirmationMode::UnconfirmedSolo) {
                $version->forceFill([
                    'confirmation_mode' => ConfirmationMode::UnconfirmedSolo,
                    'status' => ReceivingDetailsStatus::Scheduled,
                ])->save();
            }

            return $version;
        });

        $alert = $this->alerts->ensure($workspace->id, AlertType::ReceivingDetailsChangeRequested, AlertSubjectType::ReceivingDetailsVersion, $version->id, 'rdv:'.$version->id, [
            'user' => UserRef::name($requester),
            'last4' => self::last4($details),
            'version' => $version->version,
        ]);
        $this->notifications->receivingDetailsChangeRequested($version, self::last4($details), $alert);

        return $version;
    }

    public function confirm(ReceivingDetailsVersion $version, User $confirmer): ReceivingDetailsVersion
    {
        $denial = $this->confirmDenial($version, $confirmer);

        if ($denial !== null) {
            throw new GuardException($denial, $denial === 'no_permission' ? 403 : 409);
        }

        return DB::transaction(function () use ($version, $confirmer): ReceivingDetailsVersion {
            $this->markConfirmed($version, ConfirmationMode::SecondPerson, $confirmer->id, null);
            $this->auditor->record('payflow.receiving_details.confirmed', $version, metadata: ['mode' => 'second_person', 'version' => $version->version], actor: $confirmer, workspace: $version->workspace_id, subjectLabel: 'v'.$version->version);

            return $version;
        });
    }

    public function trustedDecision(ReceivingDetailsVersion $version, TrustedConfirmation $confirmation, bool $confirm, ?string $comment): ReceivingDetailsVersion
    {
        if ($version->status !== ReceivingDetailsStatus::PendingConfirmation) {
            throw new GuardException('not_pending');
        }

        return DB::transaction(function () use ($version, $confirmation, $confirm, $comment): ReceivingDetailsVersion {
            if ($confirm) {
                $this->markConfirmed($version, ConfirmationMode::TrustedContact, null, $confirmation->id);
                $this->auditor->record('payflow.receiving_details.confirmed', $version, metadata: ['mode' => 'trusted_contact', 'contact' => $confirmation->contact->name], workspace: $version->workspace_id, subjectLabel: 'v'.$version->version, system: true);
            } else {
                $version->forceFill([
                    'status' => ReceivingDetailsStatus::Rejected,
                    'rejected_at' => $this->clock->now(),
                    'rejection_reason' => $comment !== null && trim($comment) !== '' ? mb_substr(trim($comment), 0, 500) : 'Declined by the trusted contact',
                ])->save();
                $this->alerts->resolve($version->workspace_id, 'rdv:'.$version->id);
                $this->auditor->record('payflow.receiving_details.rejected', $version, metadata: ['mode' => 'trusted_contact', 'contact' => $confirmation->contact->name], workspace: $version->workspace_id, subjectLabel: 'v'.$version->version, system: true);
            }

            return $version;
        });
    }

    public function reject(ReceivingDetailsVersion $version, User $actor, string $reason): ReceivingDetailsVersion
    {
        if (! in_array($version->status, [ReceivingDetailsStatus::PendingConfirmation, ReceivingDetailsStatus::Scheduled], true)) {
            throw new GuardException('not_pending');
        }

        $role = $actor->roleIn($version->workspace_id);

        if ($role === null || ! ($role->allows('guard.configure') || $role->allows('payouts.approve'))) {
            throw new GuardException('no_permission', 403);
        }

        return DB::transaction(function () use ($version, $actor, $reason): ReceivingDetailsVersion {
            $version->forceFill([
                'status' => ReceivingDetailsStatus::Rejected,
                'rejected_by' => $actor->id,
                'rejected_at' => $this->clock->now(),
                'rejection_reason' => mb_substr(trim($reason), 0, 500),
            ])->save();
            $this->cancelConfirmations($version);
            $this->alerts->resolve($version->workspace_id, 'rdv:'.$version->id, $actor);
            $this->auditor->record('payflow.receiving_details.rejected', $version, metadata: ['reason' => trim($reason)], actor: $actor, workspace: $version->workspace_id, subjectLabel: 'v'.$version->version);

            return $version;
        });
    }

    public function cancel(ReceivingDetailsVersion $version, User $actor): ReceivingDetailsVersion
    {
        if ($version->requested_by !== $actor->id) {
            throw new GuardException('no_permission', 403);
        }

        if (! in_array($version->status, [ReceivingDetailsStatus::PendingConfirmation, ReceivingDetailsStatus::Scheduled], true)) {
            throw new GuardException('not_pending');
        }

        return DB::transaction(function () use ($version, $actor): ReceivingDetailsVersion {
            $version->forceFill(['status' => ReceivingDetailsStatus::Canceled])->save();
            $this->cancelConfirmations($version);
            $this->alerts->resolve($version->workspace_id, 'rdv:'.$version->id, $actor);
            $this->auditor->record('payflow.receiving_details.canceled', $version, actor: $actor, workspace: $version->workspace_id, subjectLabel: 'v'.$version->version);

            return $version;
        });
    }

    public function activateDue(?CarbonImmutable $now = null): int
    {
        $now ??= $this->clock->now();
        $due = ReceivingDetailsVersion::query()->withoutGlobalScopes()
            ->where('status', ReceivingDetailsStatus::Scheduled->value)
            ->whereNotNull('effective_at')
            ->where('effective_at', '<=', $now)
            ->orderBy('id')
            ->get();

        foreach ($due as $version) {
            DB::transaction(function () use ($version, $now): void {
                $previous = ReceivingDetailsVersion::query()->forWorkspace($version->workspace_id)->where('status', ReceivingDetailsStatus::Active->value)->get();

                foreach ($previous as $old) {
                    $old->forceFill(['status' => ReceivingDetailsStatus::Superseded])->save();
                }

                $version->forceFill(['status' => ReceivingDetailsStatus::Active])->save();
                PayflowSettings::query()->forWorkspace($version->workspace_id)->update(['active_receiving_details_id' => $version->id, 'updated_at' => $now]);

                if ($version->confirmation_mode !== ConfirmationMode::UnconfirmedSolo) {
                    $this->alerts->resolve($version->workspace_id, 'rdv:'.$version->id);
                }

                $alert = $this->alerts->ensure($version->workspace_id, AlertType::ReceivingDetailsChanged, AlertSubjectType::ReceivingDetailsVersion, $version->id, 'rd:'.$version->id, [
                    'version' => $version->version,
                    'user' => UserRef::name($version->requester),
                ]);
                $this->auditor->record('payflow.receiving_details.activated', $version, metadata: ['version' => $version->version], workspace: $version->workspace_id, subjectLabel: 'v'.$version->version, system: true);
                $this->notifications->receivingDetailsChanged($version, $alert);
            });
        }

        return $due->count();
    }

    public function confirmDenial(ReceivingDetailsVersion $version, User $user): ?string
    {
        if ($version->status !== ReceivingDetailsStatus::PendingConfirmation) {
            return 'not_pending';
        }

        if ($version->requested_by === $user->id) {
            return 'is_requester';
        }

        $member = $this->eligibility->find($version->workspace_id, $user);

        if ($member === null || ! ($member->role === WorkspaceRole::Owner || $member->role->allows('payouts.approve'))) {
            return 'no_permission';
        }

        return $member->reason();
    }

    public function confirmationMode(Workspace $workspace, User $requester): ConfirmationMode
    {
        $owners = array_filter($this->eligibility->members($workspace, 'guard.configure'), fn (EligibleMember $member): bool => $member->eligible() && $member->user->id !== $requester->id);
        $approvers = array_filter($this->eligibility->members($workspace, 'payouts.approve'), fn (EligibleMember $member): bool => $member->eligible() && $member->user->id !== $requester->id);

        if ($owners !== [] || $approvers !== []) {
            return ConfirmationMode::SecondPerson;
        }

        if ($this->evaluator->activeTrustedContact($workspace->id, $this->clock->now()) !== null) {
            return ConfirmationMode::TrustedContact;
        }

        return ConfirmationMode::UnconfirmedSolo;
    }

    public static function last4(string $details): string
    {
        if (preg_match_all('/[A-Z]{2}\d{2}[A-Z0-9 ]{8,40}|\d{6,}/', strtoupper($details), $matches) > 0) {
            $candidate = (string) preg_replace('/\s+/', '', (string) $matches[0][0]);

            return substr($candidate, -4);
        }

        $digits = (string) preg_replace('/\D/', '', $details);

        return $digits !== '' ? substr($digits, -4) : '----';
    }

    private function markConfirmed(ReceivingDetailsVersion $version, ConfirmationMode $mode, ?int $confirmerId, ?int $confirmationId): void
    {
        $now = $this->clock->now();
        $effective = $version->requested_at->copy()->addHours(self::DELAY_HOURS);
        $version->forceFill([
            'status' => ReceivingDetailsStatus::Scheduled,
            'confirmation_mode' => $mode,
            'confirmed_by' => $confirmerId,
            'trusted_confirmation_id' => $confirmationId,
            'confirmed_at' => $now,
            'effective_at' => $effective->greaterThan($now) ? $effective : $now,
        ])->save();
    }

    private function cancelConfirmations(ReceivingDetailsVersion $version): void
    {
        TrustedConfirmation::query()->forWorkspace($version->workspace_id)
            ->where('subject_type', TrustedSubjectType::ReceivingDetailsVersion->value)
            ->where('subject_id', $version->id)
            ->where('status', TrustedConfirmationStatus::Pending->value)
            ->update(['status' => TrustedConfirmationStatus::Canceled->value]);
    }
}
