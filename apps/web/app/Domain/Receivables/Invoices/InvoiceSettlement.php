<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Invoices;

use App\Domain\Analytics\Risk\RiskRecomputer;
use App\Domain\Collections\Dispatch\ReminderCanceller;
use App\Domain\Collections\Enums\ReminderSkipReason;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Guard\Alerts\GuardAlerts;
use App\Domain\Payments\Enums\LinkDeactivationReason;
use App\Domain\Payments\Links\PaymentLinkService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Receivables\Enums\InvoiceEventType;
use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Receivables\Timeline\InvoiceTimeline;
use App\Domain\Shared\Time\Clock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class InvoiceSettlement
{
    public function __construct(
        private PaymentLinkService $links,
        private ReminderCanceller $reminders,
        private GuardAlerts $alerts,
        private InvoiceTimeline $timeline,
        private RiskRecomputer $risk,
        private Clock $clock,
    ) {}

    public static function amountPaid(Invoice $invoice): int
    {
        return (int) Payment::query()
            ->forWorkspace($invoice->workspace_id)
            ->where('invoice_id', $invoice->id)
            ->whereIn('status', Payment::COUNTED_STATUSES)
            ->sum(DB::raw('amount_minor - refunded_minor'));
    }

    public function settle(Invoice $invoice, ?User $actor = null, bool $refreshLink = true): InvoiceStatus
    {
        $previous = $invoice->status;
        $previousBalance = $invoice->balanceDue();
        $amountPaid = self::amountPaid($invoice);
        $status = InvoiceStateMachine::settledStatus($previous, $invoice->total_minor, $amountPaid);

        $invoice->forceFill(['amount_paid_minor' => $amountPaid, 'status' => $status]);

        if ($status === InvoiceStatus::Paid && $previous !== InvoiceStatus::Paid) {
            $invoice->paid_at = $this->lastPaymentAt($invoice) ?? $this->clock->now();
        }

        if ($status !== InvoiceStatus::Paid && $previous === InvoiceStatus::Paid) {
            $invoice->paid_at = null;
        }

        $invoice->save();
        $invoice->refresh();

        if ($status === InvoiceStatus::Paid && $previous !== InvoiceStatus::Paid) {
            $this->timeline->record($invoice, InvoiceEventType::Paid, ['amount_minor' => $invoice->amount_paid_minor], $actor);
            $this->closed($invoice, LinkDeactivationReason::Paid, ReminderSkipReason::InvoicePaid);
        } elseif ($refreshLink && $invoice->isOpen() && $invoice->balanceDue() !== $previousBalance) {
            $this->links->ensureActive($invoice);
        }

        $this->refreshRisk($invoice);

        return $status;
    }

    public function closed(Invoice $invoice, LinkDeactivationReason $linkReason, ReminderSkipReason $reminderReason): void
    {
        $this->links->deactivate($invoice, $linkReason);
        $this->reminders->forInvoice($invoice, $reminderReason);

        if (! in_array($invoice->status, [InvoiceStatus::Disputed], true)) {
            $this->alerts->resolve($invoice->workspace_id, 'esc:'.$invoice->id);
            $this->alerts->resolve($invoice->workspace_id, 'crp:'.$invoice->id);

            Reminder::query()->forWorkspace($invoice->workspace_id)
                ->where('invoice_id', $invoice->id)
                ->pluck('id')
                ->each(fn (int $id): int => $this->alerts->resolve($invoice->workspace_id, 'rf:'.$id));
        }
    }

    public function refreshRisk(Invoice $invoice): void
    {
        $this->risk->client($invoice->client, $this->clock->now());
        $invoice->refresh();
    }

    private function lastPaymentAt(Invoice $invoice): ?CarbonImmutable
    {
        $value = Payment::query()
            ->forWorkspace($invoice->workspace_id)
            ->where('invoice_id', $invoice->id)
            ->whereIn('status', Payment::COUNTED_STATUSES)
            ->max('paid_at');

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}
