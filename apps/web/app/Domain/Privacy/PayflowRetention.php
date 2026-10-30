<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

use App\Audit\Auditor;
use App\Domain\Imports\ImportService;
use App\Domain\Receivables\Pdf\InvoicePdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final readonly class PayflowRetention
{
    public const array KEYS = [
        'provider_event_payloads',
        'reminder_bodies',
        'import_files',
        'pdf_cache',
        'approval_network',
        'bank_change_evidence',
        'trusted_confirmation_tokens',
        'alerts',
        'digest_runs',
    ];

    public function __construct(private Auditor $auditor) {}

    public static function days(string $key): int
    {
        return (int) config('retention.payflow.'.$key, 0);
    }

    /** @return array<string, int> */
    public function purge(CarbonImmutable $now): array
    {
        $counts = [
            'provider_event_payloads' => 0,
            'reminder_bodies' => DB::table('reminders')
                ->where(fn ($query) => $query->whereNotNull('body_html')->orWhereNotNull('body_text'))
                ->where('created_at', '<', $now->subDays(self::days('reminder_bodies')))
                ->update(['body_html' => null, 'body_text' => null]),
            'import_files' => $this->purgeImportFiles($now->subDays(self::days('import_files'))),
            'pdf_cache' => InvoicePdf::purge(self::days('pdf_cache')),
            'approval_network' => DB::table('payout_approvals')
                ->where(fn ($query) => $query->whereNotNull('ip')->orWhereNotNull('user_agent'))
                ->where('created_at', '<', $now->subDays(self::days('approval_network')))
                ->update(['ip' => null, 'user_agent' => null])
                + DB::table('trusted_confirmations')
                    ->where(fn ($query) => $query->whereNotNull('ip')->orWhereNotNull('user_agent'))
                    ->where('created_at', '<', $now->subDays(self::days('approval_network')))
                    ->update(['ip' => null, 'user_agent' => null]),
            'bank_change_evidence' => DB::table('vendor_bank_accounts')
                ->where(fn ($query) => $query->whereNotNull('change_request_text')->orWhereNotNull('requester_contact'))
                ->where(fn ($query) => $query
                    ->where('superseded_at', '<', $now->subDays(self::days('bank_change_evidence')))
                    ->orWhere('rejected_at', '<', $now->subDays(self::days('bank_change_evidence'))))
                ->update(['change_request_text' => null, 'requester_contact' => null]),
            'trusted_confirmation_tokens' => $this->purgeConfirmationSnapshots($now->subDays(self::days('trusted_confirmation_tokens'))),
            'alerts' => DB::table('guard_alerts')
                ->where('status', 'resolved')
                ->where('resolved_at', '<', $now->subDays(self::days('alerts')))
                ->delete(),
            'digest_runs' => DB::table('digest_runs')
                ->where('sent_at', '<', $now->subDays(self::days('digest_runs')))
                ->delete(),
        ];

        $this->auditor->record('payflow.retention.purged', metadata: ['counts' => $counts], workspace: null, platform: true, system: true);

        return $counts;
    }

    private function purgeImportFiles(CarbonImmutable $cutoff): int
    {
        $imports = DB::table('imports')
            ->whereNotNull('file_path')
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', $cutoff)
            ->get(['id', 'file_path']);

        foreach ($imports as $import) {
            Storage::disk(ImportService::disk())->delete((string) $import->file_path);
        }

        return DB::table('imports')->whereIn('id', $imports->pluck('id')->all())->update(['file_path' => null]);
    }

    private function purgeConfirmationSnapshots(CarbonImmutable $cutoff): int
    {
        $rows = DB::table('trusted_confirmations')
            ->whereIn('status', ['expired', 'declined'])
            ->where('created_at', '<', $cutoff)
            ->get(['id', 'snapshot']);
        $count = 0;

        foreach ($rows as $row) {
            $snapshot = json_decode((string) $row->snapshot, true);

            if (! is_array($snapshot)) {
                continue;
            }

            $trimmed = array_intersect_key($snapshot, array_flip(['amount_minor', 'currency']));

            if ($trimmed !== $snapshot) {
                DB::table('trusted_confirmations')->where('id', $row->id)->update(['snapshot' => json_encode($trimmed)]);
                $count++;
            }
        }

        return $count;
    }
}
