<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Invoices;

use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Shared\Exceptions\InvalidInvoiceTransition;

final class InvoiceStateMachine
{
    public const array TRANSITIONS = [
        'draft' => ['open', 'void'],
        'open' => ['partially_paid', 'paid', 'disputed', 'uncollectible', 'void'],
        'partially_paid' => ['open', 'paid', 'disputed', 'uncollectible'],
        'paid' => ['partially_paid', 'open'],
        'disputed' => ['open', 'partially_paid', 'paid', 'uncollectible'],
        'uncollectible' => ['paid'],
        'void' => [],
    ];

    public const array OPEN_EDITABLE = ['notes', 'terms', 'po_number', 'due_date'];

    public static function can(InvoiceStatus $from, InvoiceStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value], true);
    }

    public static function assert(InvoiceStatus $from, InvoiceStatus $to): void
    {
        if (! self::can($from, $to)) {
            throw new InvalidInvoiceTransition;
        }
    }

    /** @param  list<InvoiceStatus>  $allowed */
    public static function assertIn(InvoiceStatus $status, array $allowed): void
    {
        if (! in_array($status, $allowed, true)) {
            throw new InvalidInvoiceTransition;
        }
    }

    public static function settledStatus(InvoiceStatus $current, int $totalMinor, int $amountPaidMinor): InvoiceStatus
    {
        if (in_array($current, [InvoiceStatus::Draft, InvoiceStatus::Void, InvoiceStatus::Disputed], true)) {
            return $current;
        }

        if ($amountPaidMinor >= $totalMinor && $totalMinor > 0) {
            return InvoiceStatus::Paid;
        }

        if ($current === InvoiceStatus::Uncollectible) {
            return $current;
        }

        return $amountPaidMinor > 0 ? InvoiceStatus::PartiallyPaid : InvoiceStatus::Open;
    }

    public static function statusAfterDispute(int $totalMinor, int $amountPaidMinor): InvoiceStatus
    {
        if ($amountPaidMinor >= $totalMinor && $totalMinor > 0) {
            return InvoiceStatus::Paid;
        }

        return $amountPaidMinor > 0 ? InvoiceStatus::PartiallyPaid : InvoiceStatus::Open;
    }

    /** @return list<string> */
    public static function editableFields(InvoiceStatus $status): array
    {
        return match ($status) {
            InvoiceStatus::Draft => ['*'],
            InvoiceStatus::Open, InvoiceStatus::PartiallyPaid => self::OPEN_EDITABLE,
            default => [],
        };
    }
}
