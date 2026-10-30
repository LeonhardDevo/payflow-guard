<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

use App\Audit\Auditor;
use App\Models\User;
use App\Models\Workspace;
use App\Privacy\ExportContributor;
use Illuminate\Support\Facades\DB;

final readonly class PayflowExportContributor implements ExportContributor
{
    public const array MASKED_BANK_COLUMNS = ['account_holder_name', 'iban', 'account_number', 'routing_number', 'swift_bic', 'requester_contact', 'change_request_text'];

    public const array AR_TABLES = [
        'clients' => 'payflow_clients',
        'invoices' => 'payflow_invoices',
        'invoice_items' => 'payflow_invoice_items',
        'invoice_events' => 'payflow_invoice_events',
        'payments' => 'payflow_payments',
        'reminders' => 'payflow_reminders',
        'reminder_policies' => 'payflow_reminder_policies',
        'reminder_policy_steps' => 'payflow_reminder_policy_steps',
        'message_templates' => 'payflow_message_templates',
        'imports' => 'payflow_imports',
        'payflow_settings' => 'payflow_settings',
    ];

    public const array GUARD_TABLES = [
        'vendors' => 'payflow_vendors',
        'vendor_bank_accounts' => 'payflow_vendor_bank_accounts',
        'payout_requests' => 'payflow_payout_requests',
        'payout_approvals' => 'payflow_payout_approvals',
        'guard_alerts' => 'payflow_guard_alerts',
        'receiving_details_versions' => 'payflow_receiving_details_versions',
        'trusted_contacts' => 'payflow_trusted_contacts',
    ];

    public function __construct(private Auditor $auditor) {}

    public function forWorkspace(Workspace $workspace): array
    {
        $sections = [];

        foreach ([...self::AR_TABLES, ...self::GUARD_TABLES] as $table => $file) {
            $sections[$file] = $this->rows($table, $workspace->id);
        }

        $sections['payflow_payment_links'] = DB::table('payment_links')
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('invoice_id')
            ->orderBy('id')
            ->get(['id', 'invoice_id', 'driver', 'kind', 'amount', 'currency', 'status', 'active', 'expires_at', 'deactivated_at', 'deactivation_reason', 'created_at'])
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $this->auditor->record('payflow.export.masked', $workspace, metadata: [
            'tables' => count($sections),
            'rows' => array_sum(array_map(fn (array $rows): int => count($rows), $sections)),
        ], workspace: $workspace, subjectLabel: $workspace->name, system: true);

        return $sections;
    }

    public function forUser(User $user): array
    {
        return [
            'payflow_payout_approvals' => DB::table('payout_approvals')->where('user_id', $user->id)->orderBy('id')
                ->get(['id', 'workspace_id', 'payout_request_id', 'decision', 'channel', 'comment', 'amount_minor', 'currency', 'risk_band', 'approval_round', 'created_at'])
                ->map(fn (object $row): array => (array) $row)->all(),
            'payflow_bank_verifications' => DB::table('vendor_bank_accounts')->where('verified_by_user_id', $user->id)->orderBy('id')
                ->get(['id', 'workspace_id', 'vendor_id', 'version', 'last4', 'status', 'verification_method', 'verified_at'])
                ->map(fn (object $row): array => (array) $row)->all(),
            'payflow_manual_reminders' => DB::table('reminders')->where('created_by', $user->id)->orderBy('id')
                ->get(['id', 'workspace_id', 'invoice_id', 'tone', 'status', 'subject', 'scheduled_for', 'sent_at'])
                ->map(fn (object $row): array => (array) $row)->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $table, int $workspaceId): array
    {
        return array_values(DB::table($table)
            ->where('workspace_id', $workspaceId)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => self::mask($table, (array) $row))
            ->all());
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function mask(string $table, array $row): array
    {
        if ($table === 'vendor_bank_accounts') {
            foreach (self::MASKED_BANK_COLUMNS as $column) {
                if (array_key_exists($column, $row) && $row[$column] !== null) {
                    $row[$column] = '***';
                }
            }
        }

        if ($table === 'vendors' && ($row['tax_id'] ?? null) !== null) {
            $row['tax_id'] = '***';
        }

        if ($table === 'receiving_details_versions' && ($row['details'] ?? null) !== null) {
            $row['details'] = '***';
        }

        if ($table === 'trusted_contacts' && is_string($row['email'] ?? null)) {
            $row['email'] = self::maskEmail($row['email']);
        }

        if ($table === 'payout_approvals') {
            unset($row['ip'], $row['user_agent']);
        }

        return $row;
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }
}
