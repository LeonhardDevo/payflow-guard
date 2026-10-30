<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Invoices;

use App\Domain\Receivables\Enums\InvoiceDisplayStatus;
use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Time\BusinessDate;
use Carbon\CarbonInterface;

final class InvoiceDisplayStatusResolver
{
    public static function resolve(InvoiceStatus $status, string $dueDate, CarbonInterface|string $today): InvoiceDisplayStatus
    {
        $d = BusinessDate::diff($dueDate, $today);

        return match (true) {
            $status === InvoiceStatus::Void => InvoiceDisplayStatus::Void,
            $status === InvoiceStatus::Paid => InvoiceDisplayStatus::Paid,
            $status === InvoiceStatus::Uncollectible => InvoiceDisplayStatus::Uncollectible,
            $status === InvoiceStatus::Disputed => InvoiceDisplayStatus::Disputed,
            $status === InvoiceStatus::Draft => InvoiceDisplayStatus::Draft,
            $d >= 1 => InvoiceDisplayStatus::Overdue,
            $status === InvoiceStatus::PartiallyPaid => InvoiceDisplayStatus::PartiallyPaid,
            $d >= -3 => InvoiceDisplayStatus::DueSoon,
            default => InvoiceDisplayStatus::Open,
        };
    }

    public static function for(Invoice $invoice, CarbonInterface|string $today): InvoiceDisplayStatus
    {
        return self::resolve($invoice->status, $invoice->dueDate(), $today);
    }

    public static function daysOverdue(string $dueDate, CarbonInterface|string $today): int
    {
        return max(0, BusinessDate::diff($dueDate, $today));
    }

    public static function daysUntilDue(string $dueDate, CarbonInterface|string $today): ?int
    {
        $days = BusinessDate::diff($today, $dueDate);

        return $days >= 0 ? $days : null;
    }
}
