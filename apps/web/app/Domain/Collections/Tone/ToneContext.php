<?php

declare(strict_types=1);

namespace App\Domain\Collections\Tone;

use App\Domain\Analytics\Risk\ClientStatsCalculator;
use App\Domain\Collections\Enums\StepTone;
use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Time\BusinessDate;
use Carbon\CarbonInterface;

final class ToneContext
{
    public static function input(Invoice $invoice, CarbonInterface|string $today, StepTone $stepTone = StepTone::Auto): ToneInput
    {
        $client = $invoice->client;
        $today = BusinessDate::of($today);

        return new ToneInput(
            daysSinceDue: BusinessDate::diff($invoice->dueDate(), $today),
            clientClass: $client->late_payer_class,
            amountRatio: self::amountRatio($invoice, $today),
            remindersSent: $invoice->reminders_sent_count,
            otherOverdueInvoices: self::otherOverdue($invoice, $today),
            ceiling: $client->tone_ceiling,
            stepTone: $stepTone,
            avgDaysLate: $client->avgDaysLate(),
            onTimeRate: $client->onTimeRate(),
        );
    }

    public static function suggest(Invoice $invoice, CarbonInterface|string $today, StepTone $stepTone = StepTone::Auto): ToneSuggestion
    {
        return ToneEngine::suggest(self::input($invoice, $today, $stepTone));
    }

    public static function amountRatio(Invoice $invoice, string $today): float
    {
        if ($invoice->client->paid_invoices_count < 3) {
            return 1.0;
        }

        $totals = Invoice::query()
            ->forWorkspace($invoice->workspace_id)
            ->where('client_id', $invoice->client_id)
            ->where('currency', $invoice->currency)
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Void->value])
            ->where('issue_date', '>=', BusinessDate::addDays($today, -731))
            ->pluck('total_minor')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        $median = ClientStatsCalculator::median(array_values($totals));

        return $median > 0 ? $invoice->total_minor / $median : 1.0;
    }

    public static function otherOverdue(Invoice $invoice, string $today): int
    {
        return Invoice::query()
            ->forWorkspace($invoice->workspace_id)
            ->where('client_id', $invoice->client_id)
            ->whereKeyNot($invoice->id)
            ->whereIn('status', Invoice::OPEN_STATUSES)
            ->where('due_date', '<', $today)
            ->count();
    }
}
