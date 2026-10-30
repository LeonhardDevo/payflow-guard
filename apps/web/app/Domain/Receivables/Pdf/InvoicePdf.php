<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Pdf;

use App\Domain\Collections\Email\ClientEmailComposer;
use App\Domain\Guard\Models\ReceivingDetailsVersion;
use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Receivables\Models\InvoiceItem;
use App\Domain\Shared\Money\MoneyFormatter;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

final class InvoicePdf
{
    public static function disk(): string
    {
        return (string) config('payflow.pdf.disk', 'local');
    }

    public static function cachePath(Invoice $invoice): string
    {
        $stamp = $invoice->updated_at?->getTimestamp() ?? 0;

        return 'invoices/'.$invoice->workspace_id.'/'.$invoice->id.'-'.$stamp.'.pdf';
    }

    public static function render(Invoice $invoice): string
    {
        $path = self::cachePath($invoice);
        $disk = Storage::disk(self::disk());

        if ($disk->exists($path)) {
            return (string) $disk->get($path);
        }

        $output = Pdf::setOptions([
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
            'defaultFont' => 'DejaVu Sans',
            'defaultPaperSize' => 'a4',
        ])->loadView('pdf.invoice', self::data($invoice))->setPaper('a4')->output();

        $disk->put($path, $output);

        return $output;
    }

    /** @return array<string, mixed> */
    public static function data(Invoice $invoice): array
    {
        $workspace = $invoice->workspace;
        $settings = WorkspaceProvisioner::settingsFor($workspace);
        $locale = $invoice->locale;
        $client = $invoice->client;
        $currency = $invoice->currency;
        $format = fn (int $minor): string => MoneyFormatter::format($minor, $currency, $locale);
        $details = $settings->active_receiving_details_id !== null
            ? ReceivingDetailsVersion::query()->forWorkspace($workspace)->find($settings->active_receiving_details_id)
            : null;

        return [
            'locale' => $locale,
            'title' => ClientEmailComposer::fileName($invoice),
            'status_label' => match ($invoice->status) {
                InvoiceStatus::Paid => (string) trans('payflow.pdf.status_paid', [], $locale),
                InvoiceStatus::Void => (string) trans('payflow.pdf.status_void', [], $locale),
                default => null,
            },
            'company' => [
                'name' => $workspace->name,
                'legal_name' => $settings->company_legal_name,
                'address' => $settings->company_address,
                'tax_id' => $settings->company_tax_id,
                'email' => $settings->company_email,
                'phone' => $settings->company_phone,
            ],
            'client' => [
                'name' => $client->name,
                'legal_name' => $client->legal_name,
                'address' => $client->address,
                'tax_id' => $client->tax_id,
            ],
            'invoice' => [
                'number' => $invoice->label(),
                'issue_date' => ClientEmailComposer::longDate($invoice->issueDate(), $locale),
                'due_date' => ClientEmailComposer::longDate($invoice->dueDate(), $locale),
                'po_number' => $invoice->po_number,
                'notes' => $invoice->notes,
                'terms' => $invoice->terms,
            ],
            'items' => $invoice->items()->get()->map(fn (InvoiceItem $item): array => [
                'description' => $item->description,
                'quantity' => str_contains($item->quantity, '.') ? rtrim(rtrim($item->quantity, '0'), '.') : $item->quantity,
                'unit_price' => $format($item->unit_price_minor),
                'tax_rate' => rtrim(rtrim(number_format($item->tax_rate_bp / 100, 2, '.', ''), '0'), '.').'%',
                'amount' => $format($item->amount_minor),
            ])->values()->all(),
            'totals' => [
                'subtotal' => $format($invoice->subtotal_minor),
                'tax' => $format($invoice->tax_minor),
                'total' => $format($invoice->total_minor),
                'paid' => $invoice->amount_paid_minor > 0 ? $format($invoice->amount_paid_minor) : null,
                'balance' => $format($invoice->balanceDue()),
            ],
            'receiving_details' => $details?->details,
            'pay_url' => $invoice->isOpen() ? ClientEmailComposer::publicUrl($invoice) : null,
            'privacy_contact' => ClientEmailComposer::privacyContact($workspace, $settings),
            'recipients_url' => ClientEmailComposer::legalUrl('reminder-recipients', $locale),
        ];
    }

    public static function purge(int $olderThanDays): int
    {
        $disk = Storage::disk(self::disk());
        $cutoff = now()->subDays($olderThanDays)->getTimestamp();
        $deleted = 0;

        foreach ($disk->allFiles('invoices') as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }
}
