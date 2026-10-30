<?php

declare(strict_types=1);

namespace App\Http\Resources\Payflow;

use App\Domain\Collections\Enums\ReminderStatus;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Payments\Links\PaymentLinkService;
use App\Domain\Payments\Models\InvoicePaymentLink;
use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Receivables\Invoices\InvoiceDisplayStatusResolver;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Receivables\Models\InvoiceItem;
use App\Domain\Shared\Money\MoneyData;
use App\Domain\Shared\Support\Iso;
use App\Domain\Shared\Support\UserRef;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class InvoiceResource
{
    /**
     * @param  iterable<Invoice>  $invoices
     * @return array<int, string>
     */
    public static function nextReminders(iterable $invoices): array
    {
        $ids = [];

        foreach ($invoices as $invoice) {
            $ids[] = $invoice->id;
        }

        if ($ids === []) {
            return [];
        }

        return Reminder::query()
            ->withoutGlobalScopes()
            ->whereIn('invoice_id', $ids)
            ->where('status', ReminderStatus::Scheduled->value)
            ->selectRaw('invoice_id, min(scheduled_for) as next_at')
            ->groupBy('invoice_id')
            ->pluck('next_at', 'invoice_id')
            ->map(fn (mixed $value): string => (string) Iso::dateTime(CarbonImmutable::parse((string) $value)))
            ->all();
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @return list<array<string, mixed>>
     */
    public static function listItems(Collection $invoices, string $today, bool $riskVisible): array
    {
        $next = self::nextReminders($invoices);

        return array_values($invoices->map(fn (Invoice $invoice): array => self::listItem($invoice, $today, $riskVisible, $next[$invoice->id] ?? null))->all());
    }

    /** @return array<string, mixed> */
    public static function listItem(Invoice $invoice, string $today, bool $riskVisible, ?string $nextReminderAt = null): array
    {
        $due = $invoice->dueDate();

        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'client' => ClientResource::ref($invoice->client),
            'status' => $invoice->status->value,
            'display_status' => InvoiceDisplayStatusResolver::for($invoice, $today)->value,
            'is_partially_paid' => $invoice->status === InvoiceStatus::PartiallyPaid || ($invoice->amount_paid_minor > 0 && $invoice->balanceDue() > 0 && $invoice->status === InvoiceStatus::Disputed),
            'issue_date' => $invoice->issueDate(),
            'due_date' => $due,
            'days_overdue' => $invoice->isOpen() ? InvoiceDisplayStatusResolver::daysOverdue($due, $today) : 0,
            'days_until_due' => $invoice->isOpen() ? InvoiceDisplayStatusResolver::daysUntilDue($due, $today) : null,
            'total' => MoneyData::of($invoice->total_minor, $invoice->currency),
            'amount_paid' => MoneyData::of($invoice->amount_paid_minor, $invoice->currency),
            'balance_due' => MoneyData::of($invoice->balanceDue(), $invoice->currency),
            'risk' => $riskVisible && $invoice->risk_score !== null && $invoice->risk_band !== null ? ['score' => $invoice->risk_score, 'band' => $invoice->risk_band->value] : null,
            'next_reminder_at' => $nextReminderAt,
            'last_reminder_at' => Iso::dateTime($invoice->last_reminder_at),
            'reminders_sent_count' => $invoice->reminders_sent_count,
            'reminders_paused' => $invoice->reminders_paused,
            'reminders_pause_source' => $invoice->reminders_pause_source?->value,
            'client_reported_paid_at' => Iso::dateTime($invoice->client_reported_paid_at),
            'first_viewed_at' => Iso::dateTime($invoice->first_viewed_at),
            'source' => $invoice->source->value,
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(Invoice $invoice, string $today, bool $riskVisible, PaymentLinkService $links): array
    {
        $base = self::listItem($invoice, $today, false, self::nextReminders([$invoice])[$invoice->id] ?? null);
        unset($base['risk'], $base['client']);
        $client = $invoice->client;
        $link = $links->active($invoice);
        $reason = $links->payableReason($invoice);

        return [
            ...$base,
            'client' => [
                ...ClientResource::ref($client),
                'email' => $client->email,
                'locale' => $client->locale,
                'tone_ceiling' => $client->tone_ceiling->value,
                'reminders_paused' => $client->reminders_paused,
                'reminders_pause_source' => $client->reminders_pause_source?->value,
            ],
            'locale' => $invoice->locale,
            'currency' => $invoice->currency,
            'po_number' => $invoice->po_number,
            'notes' => $invoice->notes,
            'terms' => $invoice->terms,
            'items' => $invoice->items()->get()->map(fn (InvoiceItem $item): array => self::item($item, $invoice->currency))->values()->all(),
            'subtotal' => MoneyData::of($invoice->subtotal_minor, $invoice->currency),
            'tax' => MoneyData::of($invoice->tax_minor, $invoice->currency),
            'late_fee_applied' => MoneyData::of($invoice->late_fee_applied_minor, $invoice->currency),
            'public_url' => route('public.invoices.show', ['token' => $invoice->public_token]),
            'pdf_url' => route('invoices.pdf', $invoice->id),
            'sent_at' => Iso::dateTime($invoice->sent_at),
            'first_viewed_at' => Iso::dateTime($invoice->first_viewed_at),
            'last_viewed_at' => Iso::dateTime($invoice->last_viewed_at),
            'view_count' => $invoice->view_count,
            'paid_at' => Iso::dateTime($invoice->paid_at),
            'voided_at' => Iso::dateTime($invoice->voided_at),
            'void_reason' => $invoice->void_reason,
            'written_off_at' => Iso::dateTime($invoice->written_off_at),
            'write_off_reason' => $invoice->write_off_reason,
            'disputed_at' => Iso::dateTime($invoice->disputed_at),
            'dispute_reason' => $invoice->dispute_reason,
            'dispute_resolved_at' => Iso::dateTime($invoice->dispute_resolved_at),
            'reminders_paused_until' => Iso::date($invoice->reminders_paused_until),
            'client_reported_reference' => $invoice->client_reported_reference,
            'first_contact_confirmed_at' => Iso::dateTime($invoice->first_contact_confirmed_at),
            'risk' => $riskVisible && $invoice->risk_score !== null && $invoice->risk_band !== null ? [
                'score' => $invoice->risk_score,
                'band' => $invoice->risk_band->value,
                'factors' => $invoice->risk_factors ?? [],
                'expected_paid_on' => Iso::date($invoice->expected_paid_on),
                'computed_at' => Iso::dateTime($invoice->risk_computed_at),
            ] : null,
            'payment_link' => $link !== null ? self::link($link) : null,
            'payable_reason' => $reason?->value,
            'created_by' => UserRef::of($invoice->creator),
            'created_at' => Iso::dateTime($invoice->created_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function item(InvoiceItem $item, string $currency): array
    {
        return [
            'id' => $item->id,
            'position' => $item->position,
            'kind' => $item->kind->value,
            'description' => $item->description,
            'quantity' => self::quantity($item->quantity),
            'unit_price' => MoneyData::of($item->unit_price_minor, $currency),
            'tax_rate_bp' => $item->tax_rate_bp,
            'amount' => MoneyData::of($item->amount_minor, $currency),
            'tax' => MoneyData::of($item->tax_minor, $currency),
        ];
    }

    public static function quantity(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    /** @return array<string, mixed> */
    public static function link(InvoicePaymentLink $link): array
    {
        return [
            'id' => $link->id,
            'driver' => $link->driver,
            'kind' => $link->kind->value ?? 'reusable',
            'url' => $link->url,
            'amount' => MoneyData::of($link->amount, $link->currency),
            'active' => $link->active,
            'expires_at' => Iso::dateTime($link->expires_at),
            'created_at' => Iso::dateTime($link->created_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function publicInvoice(Invoice $invoice, string $today): array
    {
        return [
            'number' => $invoice->label(),
            'status' => $invoice->status->value,
            'display_status' => InvoiceDisplayStatusResolver::for($invoice, $today)->value,
            'issue_date' => $invoice->issueDate(),
            'due_date' => $invoice->dueDate(),
            'days_overdue' => $invoice->isOpen() ? InvoiceDisplayStatusResolver::daysOverdue($invoice->dueDate(), $today) : 0,
            'locale' => $invoice->locale,
            'currency' => $invoice->currency,
            'po_number' => $invoice->po_number,
            'notes' => $invoice->notes,
            'terms' => $invoice->terms,
            'items' => $invoice->items()->get()->map(fn (InvoiceItem $item): array => [
                'description' => $item->description,
                'quantity' => self::quantity($item->quantity),
                'unit_price' => MoneyData::of($item->unit_price_minor, $invoice->currency),
                'amount' => MoneyData::of($item->amount_minor, $invoice->currency),
                'tax' => MoneyData::of($item->tax_minor, $invoice->currency),
                'tax_rate_bp' => $item->tax_rate_bp,
            ])->values()->all(),
            'subtotal' => MoneyData::of($invoice->subtotal_minor, $invoice->currency),
            'tax' => MoneyData::of($invoice->tax_minor, $invoice->currency),
            'total' => MoneyData::of($invoice->total_minor, $invoice->currency),
            'amount_paid' => MoneyData::of($invoice->amount_paid_minor, $invoice->currency),
            'balance_due' => MoneyData::of($invoice->balanceDue(), $invoice->currency),
            'paid_at' => Iso::dateTime($invoice->paid_at),
        ];
    }
}
