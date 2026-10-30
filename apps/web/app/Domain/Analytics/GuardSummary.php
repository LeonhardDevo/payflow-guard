<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Analytics\Enums\RiskBand;
use App\Domain\Guard\Enums\AlertSeverity;
use App\Domain\Guard\Enums\AlertStatus;
use App\Domain\Guard\Enums\BankAccountStatus;
use App\Domain\Guard\Enums\PayoutStatus;
use App\Domain\Guard\Models\GuardAlert;
use App\Domain\Guard\Models\PayoutApproval;
use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Shared\Money\MoneyData;
use App\Domain\Shared\Support\Iso;
use App\Domain\Shared\Support\UserRef;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

final class GuardSummary
{
    /** @return array<string, mixed> */
    public static function forDashboard(Workspace $workspace, User $viewer, WorkspaceRole $role): array
    {
        $pending = PayoutRequest::query()->forWorkspace($workspace)
            ->with(['vendor', 'requester'])
            ->where('status', PayoutStatus::PendingApproval->value)
            ->orderBy('submitted_at')
            ->limit(20)
            ->get();

        $items = $pending->map(fn (PayoutRequest $payout): array => self::payoutListItem($payout, $viewer, $role))->values();
        $changes = VendorBankAccount::query()->forWorkspace($workspace)->where('status', BankAccountStatus::PendingVerification->value);

        return [
            'pending_approvals' => $items->take(5)->values()->all(),
            'awaiting_me' => $items->where('awaiting_me', true)->count(),
            'bank_changes_pending' => (clone $changes)->count(),
            'bank_changes_high' => (clone $changes)->where('risk_band', RiskBand::High->value)->count(),
            'open_alerts' => self::openAlerts($workspace, 5),
        ];
    }

    /** @return array<string, mixed> */
    public static function payoutListItem(PayoutRequest $payout, User $viewer, WorkspaceRole $role): array
    {
        $decided = PayoutApproval::query()->forWorkspace($payout->workspace_id)
            ->where('payout_request_id', $payout->id)
            ->where('approval_round', $payout->approval_round)
            ->where('user_id', $viewer->id)
            ->exists();

        return [
            'id' => $payout->id,
            'number' => $payout->number,
            'vendor' => ['id' => $payout->vendor->id, 'name' => $payout->vendor->name, 'status' => $payout->vendor->status->value],
            'amount' => MoneyData::of($payout->amount_minor, $payout->currency),
            'description' => $payout->description,
            'vendor_invoice_number' => $payout->vendor_invoice_number,
            'due_date' => Iso::date($payout->due_date),
            'status' => $payout->status->value,
            'hold_reason' => $payout->hold_reason?->value,
            'risk' => $payout->risk_score !== null && $payout->risk_band !== null ? ['score' => $payout->risk_score, 'band' => $payout->risk_band->value] : null,
            'required_approvals' => $payout->required_approvals,
            'approvals_count' => $payout->approvals_count,
            'auto_approved' => $payout->auto_approved,
            'requested_by' => UserRef::of($payout->requester),
            'submitted_at' => Iso::dateTime($payout->submitted_at),
            'approved_at' => Iso::dateTime($payout->approved_at),
            'released_at' => Iso::dateTime($payout->released_at),
            'created_at' => Iso::dateTime($payout->created_at),
            'awaiting_me' => $payout->status === PayoutStatus::PendingApproval
                && $role->allows('payouts.approve')
                && $payout->requested_by !== $viewer->id
                && ! $decided,
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function openAlerts(Workspace $workspace, int $limit): array
    {
        return array_values(GuardAlert::query()->forWorkspace($workspace)
            ->with('acknowledger')
            ->where('status', '!=', AlertStatus::Resolved->value)
            ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (GuardAlert $alert): array => self::alertItem($alert))
            ->all());
    }

    /** @return array<string, mixed> */
    public static function alertItem(GuardAlert $alert): array
    {
        $data = [];

        foreach ($alert->data as $key => $value) {
            if ($value !== null) {
                $data[(string) $key] = is_float($value) ? round($value, 2) : $value;
            }
        }

        $label = (string) ($alert->data['number'] ?? $alert->data['client'] ?? $alert->data['vendor'] ?? $alert->data['member'] ?? $alert->data['contact'] ?? ('#'.$alert->subject_id));
        $url = match ($alert->subject_type->value) {
            'invoice' => route('invoices.show', $alert->subject_id),
            'client' => route('clients.show', $alert->subject_id),
            'vendor_bank_account' => route('guard.bank-changes.show', $alert->subject_id),
            'payout_request' => route('payouts.show', $alert->subject_id),
            'vendor' => route('vendors.show', $alert->subject_id),
            'receiving_details_version' => route('settings.collections'),
            'trusted_contact', 'workspace' => route('settings.guard'),
            'workspace_member' => route('settings.members'),
            'payment_account' => route('settings.payments'),
        };

        return [
            'id' => $alert->id,
            'type' => $alert->type->value,
            'severity' => $alert->severity->value,
            'status' => $alert->status->value,
            'subject' => ['type' => $alert->subject_type->value, 'id' => $alert->subject_id, 'label' => $label, 'url' => $url],
            'data' => $data,
            'created_at' => Iso::dateTime($alert->created_at),
            'acknowledged_by' => UserRef::of($alert->acknowledger),
        ];
    }

    public static function criticalCount(Workspace $workspace): int
    {
        return GuardAlert::query()->forWorkspace($workspace)
            ->where('status', '!=', AlertStatus::Resolved->value)
            ->where('severity', AlertSeverity::Critical->value)
            ->count();
    }
}
