<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Invoices;

use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Models\PayflowSettings;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use Illuminate\Support\Facades\DB;

final class InvoiceNumbering
{
    public static function format(string $prefix, int $year, int $sequence): string
    {
        return $prefix.$year.'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    public static function preview(int $workspaceId, string $issueDate): string
    {
        $settings = WorkspaceProvisioner::settingsFor($workspaceId);

        return self::format($settings->invoice_prefix, (int) substr($issueDate, 0, 4), $settings->next_invoice_seq);
    }

    public static function assign(Invoice $invoice): string
    {
        if ($invoice->number !== null) {
            return $invoice->number;
        }

        WorkspaceProvisioner::settingsFor($invoice->workspace_id);

        return DB::transaction(function () use ($invoice): string {
            $settings = PayflowSettings::query()->forWorkspace($invoice->workspace_id)->lockForUpdate()->firstOrFail();
            $year = (int) substr($invoice->issueDate(), 0, 4);

            do {
                $number = self::format($settings->invoice_prefix, $year, $settings->next_invoice_seq);
                $settings->next_invoice_seq++;
                $taken = Invoice::query()->forWorkspace($invoice->workspace_id)->withTrashed()->where('number', $number)->exists();
            } while ($taken);

            $settings->save();

            return $number;
        });
    }

    public static function isTaken(int $workspaceId, string $number, ?int $exceptId = null): bool
    {
        return Invoice::query()
            ->forWorkspace($workspaceId)
            ->withTrashed()
            ->whereRaw('lower(number) = ?', [mb_strtolower($number)])
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }
}
