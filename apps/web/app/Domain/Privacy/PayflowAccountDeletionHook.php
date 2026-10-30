<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

use App\Models\User;
use App\Workspaces\Contracts\AccountDeletionHook;
use Illuminate\Support\Facades\DB;

final class PayflowAccountDeletionHook implements AccountDeletionHook
{
    public const array REFERENCES = [
        'vendor_bank_accounts' => ['requested_by_user_id', 'verified_by_user_id', 'rejected_by_user_id'],
        'payout_requests' => ['requested_by', 'released_by', 'canceled_by'],
        'payout_approvals' => ['user_id'],
        'payments' => ['recorded_by', 'reversed_by'],
        'imports' => ['created_by', 'activated_by'],
        'receiving_details_versions' => ['requested_by', 'confirmed_by', 'rejected_by'],
        'trusted_contacts' => ['created_by', 'revoked_by'],
        'trusted_confirmations' => ['created_by'],
        'invoices' => ['created_by', 'first_contact_confirmed_by'],
        'clients' => ['owner_user_id', 'created_by'],
        'reminders' => ['created_by'],
        'message_templates' => ['updated_by'],
        'vendors' => ['created_by'],
        'guard_alerts' => ['acknowledged_by', 'resolved_by'],
    ];

    public function mustPseudonymise(User $user): bool
    {
        foreach (self::REFERENCES as $table => $columns) {
            $exists = DB::table($table)
                ->where(function ($query) use ($columns, $user): void {
                    foreach ($columns as $column) {
                        $query->orWhere($column, $user->id);
                    }
                })
                ->exists();

            if ($exists) {
                return true;
            }
        }

        return false;
    }
}
