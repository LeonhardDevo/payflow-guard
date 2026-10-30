<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Invoices;

use App\Domain\Receivables\Enums\InvoiceItemKind;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Receivables\Models\InvoiceItem;

final class InvoiceItems
{
    /** @param  list<array{description: string, quantity: string, unit_price_minor: int, tax_rate_bp: int}>  $lines */
    public static function replace(Invoice $invoice, array $lines): void
    {
        InvoiceItem::query()->forWorkspace($invoice->workspace_id)->where('invoice_id', $invoice->id)->delete();

        foreach ($lines as $index => $line) {
            self::create($invoice, $index + 1, InvoiceItemKind::Service, $line['description'], $line['quantity'], $line['unit_price_minor'], $line['tax_rate_bp']);
        }

        self::recalculate($invoice);
    }

    public static function add(Invoice $invoice, InvoiceItemKind $kind, string $description, string $quantity, int $unitPriceMinor, int $taxRateBp): InvoiceItem
    {
        $position = (int) InvoiceItem::query()->forWorkspace($invoice->workspace_id)->where('invoice_id', $invoice->id)->max('position') + 1;
        $item = self::create($invoice, $position, $kind, $description, $quantity, $unitPriceMinor, $taxRateBp);
        self::recalculate($invoice);

        return $item;
    }

    public static function recalculate(Invoice $invoice): void
    {
        $lines = InvoiceItem::query()->forWorkspace($invoice->workspace_id)
            ->where('invoice_id', $invoice->id)
            ->get()
            ->map(fn (InvoiceItem $item): array => ['amount_minor' => $item->amount_minor, 'tax_minor' => $item->tax_minor, 'kind' => $item->kind->value])
            ->all();

        $invoice->forceFill(InvoiceTotals::totals(array_values($lines)))->save();
        $invoice->unsetRelation('items');
    }

    private static function create(Invoice $invoice, int $position, InvoiceItemKind $kind, string $description, string $quantity, int $unitPriceMinor, int $taxRateBp): InvoiceItem
    {
        $amounts = InvoiceTotals::line($quantity, $unitPriceMinor, $taxRateBp);
        $item = new InvoiceItem;
        $item->forceFill([
            'workspace_id' => $invoice->workspace_id,
            'invoice_id' => $invoice->id,
            'position' => $position,
            'kind' => $kind,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price_minor' => $unitPriceMinor,
            'tax_rate_bp' => $taxRateBp,
            'amount_minor' => $amounts['amount_minor'],
            'tax_minor' => $amounts['tax_minor'],
        ])->save();

        return $item;
    }
}
