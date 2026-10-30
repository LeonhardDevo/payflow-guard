<?php

declare(strict_types=1);

namespace App\Http\Resources\Payflow;

use App\Domain\Collections\Models\Reminder;
use App\Domain\Receivables\Invoices\InvoiceDisplayStatusResolver;
use App\Domain\Shared\Money\MoneyData;
use App\Domain\Shared\Support\Iso;
use App\Domain\Shared\Support\UserRef;

final class ReminderResource
{
    /** @return array<string, mixed> */
    public static function row(Reminder $reminder): array
    {
        return [
            'id' => $reminder->id,
            'kind' => $reminder->kind->value,
            'tone' => $reminder->tone->value,
            'tone_reasons' => $reminder->tone_reasons,
            'status' => $reminder->status->value,
            'skip_reason' => $reminder->skip_reason?->value,
            'scheduled_for' => Iso::dateTime($reminder->scheduled_for),
            'sent_at' => Iso::dateTime($reminder->sent_at),
            'failed_at' => Iso::dateTime($reminder->failed_at),
            'next_retry_at' => Iso::dateTime($reminder->next_retry_at),
            'delivery_attempts' => $reminder->delivery_attempts,
            'error' => $reminder->error,
            'recipient_email' => $reminder->recipient_email,
            'subject' => $reminder->subject,
            'step_offset_days' => $reminder->step_offset_days,
            'is_first_contact' => $reminder->is_first_contact,
            'created_by' => UserRef::of($reminder->creator),
        ];
    }

    /** @return array<string, mixed> */
    public static function listItem(Reminder $reminder, string $today): array
    {
        $invoice = $reminder->invoice;

        return [
            ...self::row($reminder),
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'balance_due' => MoneyData::of($invoice->balanceDue(), $invoice->currency),
                'days_overdue' => $invoice->isOpen() ? InvoiceDisplayStatusResolver::daysOverdue($invoice->dueDate(), $today) : 0,
            ],
            'client' => ClientResource::ref($reminder->client),
        ];
    }

    /** @return array<string, mixed> */
    public static function upcoming(Reminder $reminder): array
    {
        $invoice = $reminder->invoice;

        return [
            'id' => $reminder->id,
            'scheduled_for' => Iso::dateTime($reminder->scheduled_for),
            'tone' => $reminder->tone->value,
            'invoice' => ['id' => $invoice->id, 'number' => $invoice->number, 'balance_due' => MoneyData::of($invoice->balanceDue(), $invoice->currency)],
            'client' => ClientResource::ref($reminder->client),
        ];
    }
}
