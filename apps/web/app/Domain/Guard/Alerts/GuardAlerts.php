<?php

declare(strict_types=1);

namespace App\Domain\Guard\Alerts;

use App\Domain\Guard\Enums\AlertSeverity;
use App\Domain\Guard\Enums\AlertStatus;
use App\Domain\Guard\Enums\AlertSubjectType;
use App\Domain\Guard\Enums\AlertType;
use App\Domain\Guard\Models\GuardAlert;
use App\Domain\Shared\Time\Clock;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class GuardAlerts
{
    public const array DEFAULT_SEVERITY = [
        'bank_change_requested' => 'warning',
        'bank_change_high_risk' => 'critical',
        'shared_bank_account' => 'critical',
        'vendor_phone_changed' => 'warning',
        'payout_pending_approval' => 'info',
        'payout_high_risk' => 'critical',
        'payout_on_hold' => 'warning',
        'payout_stale_approval' => 'warning',
        'insufficient_approvers' => 'warning',
        'guard_settings_changed' => 'warning',
        'guard_role_granted' => 'critical',
        'guard_role_revoked' => 'warning',
        'second_factor_missing' => 'warning',
        'trusted_contact_changed' => 'critical',
        'receiving_details_change_requested' => 'critical',
        'receiving_details_changed' => 'critical',
        'collections_escalation' => 'warning',
        'reminder_failed' => 'warning',
        'reminders_opted_out' => 'warning',
        'client_reported_paid' => 'info',
        'payment_provider_error' => 'critical',
        'payment_account_restricted' => 'critical',
    ];

    public function __construct(private Clock $clock) {}

    /** @param  array<string, string|int|float|null>  $data */
    public function ensure(
        int $workspaceId,
        AlertType $type,
        AlertSubjectType $subjectType,
        int $subjectId,
        string $dedupeKey,
        array $data = [],
        ?AlertSeverity $severity = null,
    ): GuardAlert {
        $existing = $this->active($workspaceId, $dedupeKey);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($workspaceId, $type, $subjectType, $subjectId, $dedupeKey, $data, $severity): GuardAlert {
                $alert = new GuardAlert;
                $alert->forceFill([
                    'workspace_id' => $workspaceId,
                    'type' => $type,
                    'severity' => $severity ?? AlertSeverity::from(self::DEFAULT_SEVERITY[$type->value]),
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'data' => $data,
                    'status' => AlertStatus::Open,
                    'dedupe_key' => $dedupeKey,
                ])->save();

                return $alert;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->active($workspaceId, $dedupeKey) ?? throw new \RuntimeException('Alert dedupe race could not be resolved.');
        }
    }

    public function resolve(int $workspaceId, string $dedupeKey, ?User $actor = null, ?string $note = null): int
    {
        return GuardAlert::query()
            ->forWorkspace($workspaceId)
            ->where('dedupe_key', $dedupeKey)
            ->where('status', '!=', AlertStatus::Resolved->value)
            ->update([
                'status' => AlertStatus::Resolved->value,
                'resolved_by' => $actor?->id,
                'resolved_at' => $this->clock->now(),
                'resolution_note' => $note,
                'updated_at' => $this->clock->now(),
            ]);
    }

    public function isActive(int $workspaceId, string $dedupeKey): bool
    {
        return $this->active($workspaceId, $dedupeKey) !== null;
    }

    public function acknowledge(GuardAlert $alert, User $actor): GuardAlert
    {
        if ($alert->status === AlertStatus::Open) {
            $alert->forceFill([
                'status' => AlertStatus::Acknowledged,
                'acknowledged_by' => $actor->id,
                'acknowledged_at' => $this->clock->now(),
            ])->save();
        }

        return $alert;
    }

    private function active(int $workspaceId, string $dedupeKey): ?GuardAlert
    {
        return GuardAlert::query()
            ->forWorkspace($workspaceId)
            ->where('dedupe_key', $dedupeKey)
            ->where('status', '!=', AlertStatus::Resolved->value)
            ->first();
    }
}
