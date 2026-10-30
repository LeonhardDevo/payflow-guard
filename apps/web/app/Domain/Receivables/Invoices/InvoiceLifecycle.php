<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Invoices;

use App\Audit\Auditor;
use App\Domain\Collections\Dispatch\ReminderCanceller;
use App\Domain\Collections\Enums\ReminderSkipReason;
use App\Domain\Guard\Alerts\GuardAlerts;
use App\Domain\Payments\Enums\LinkDeactivationReason;
use App\Domain\Payments\Enums\PaymentLinkKind;
use App\Domain\Payments\Links\PaymentLinkService;
use App\Domain\Payments\Models\InvoicePaymentLink;
use App\Domain\Receivables\Enums\InvoiceEventType;
use App\Domain\Receivables\Enums\InvoiceItemKind;
use App\Domain\Receivables\Enums\InvoicePauseSource;
use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Receivables\LateFees\LateFees;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Receivables\Timeline\InvoiceTimeline;
use App\Domain\Shared\Exceptions\PayflowException;
use App\Domain\Shared\Time\BusinessDate;
use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class InvoiceLifecycle
{
    public function __construct(
        private InvoiceSettlement $settlement,
        private InvoiceTimeline $timeline,
        private ReminderCanceller $reminders,
        private PaymentLinkService $links,
        private GuardAlerts $alerts,
        private Auditor $auditor,
        private Clock $clock,
    ) {}

    public function dispute(Invoice $invoice, User $actor, string $reason): Invoice
    {
        InvoiceStateMachine::assertIn($invoice->status, [InvoiceStatus::Open, InvoiceStatus::PartiallyPaid]);

        return DB::transaction(function () use ($invoice, $actor, $reason): Invoice {
            $invoice->forceFill([
                'status_before_dispute' => $invoice->status,
                'status' => InvoiceStatus::Disputed,
                'disputed_at' => $this->clock->now(),
                'dispute_reason' => $reason,
                'dispute_resolved_at' => null,
            ])->save();

            $this->settlement->closed($invoice, LinkDeactivationReason::Disputed, ReminderSkipReason::InvoiceDisputed);
            $this->timeline->record($invoice, InvoiceEventType::Disputed, [], $actor);
            $this->auditor->record('payflow.invoice.disputed', $invoice, metadata: ['reason' => mb_substr($reason, 0, 500)], actor: $actor, workspace: $invoice->workspace_id, subjectLabel: $invoice->label());
            $this->settlement->refreshRisk($invoice);

            return $invoice->refresh();
        });
    }

    public function resolveDispute(Invoice $invoice, User $actor, string $note): Invoice
    {
        InvoiceStateMachine::assertIn($invoice->status, [InvoiceStatus::Disputed]);

        return DB::transaction(function () use ($invoice, $actor, $note): Invoice {
            $status = InvoiceStateMachine::statusAfterDispute($invoice->total_minor, InvoiceSettlement::amountPaid($invoice));
            $invoice->forceFill([
                'status' => $status,
                'status_before_dispute' => null,
                'dispute_resolved_at' => $this->clock->now(),
                'paid_at' => $status === InvoiceStatus::Paid ? ($invoice->paid_at ?? $this->clock->now()) : null,
            ])->save();

            $this->timeline->record($invoice, InvoiceEventType::DisputeResolved, ['status' => $status->value], $actor);
            $this->auditor->record('payflow.invoice.dispute_resolved', $invoice, metadata: ['note' => mb_substr($note, 0, 500), 'status' => $status->value], actor: $actor, workspace: $invoice->workspace_id, subjectLabel: $invoice->label());

            if ($invoice->isOpen()) {
                $this->links->ensureActive($invoice);
            }

            $this->settlement->refreshRisk($invoice);

            return $invoice->refresh();
        });
    }

    public function void(Invoice $invoice, User $actor, string $reason): Invoice
    {
        InvoiceStateMachine::assertIn($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Open]);

        if ($invoice->amount_paid_minor !== 0) {
            throw new PayflowException('invalid_transition', 409);
        }

        return DB::transaction(function () use ($invoice, $actor, $reason): Invoice {
            $invoice->forceFill(['status' => InvoiceStatus::Void, 'voided_at' => $this->clock->now(), 'void_reason' => $reason])->save();
            $this->settlement->closed($invoice, LinkDeactivationReason::Voided, ReminderSkipReason::InvoiceVoid);
            $this->timeline->record($invoice, InvoiceEventType::Voided, [], $actor);
            $this->auditor->record('payflow.invoice.voided', $invoice, metadata: ['reason' => $reason], actor: $actor, workspace: $invoice->workspace_id, subjectLabel: $invoice->label());
            $this->settlement->refreshRisk($invoice);

            return $invoice->refresh();
        });
    }

    public function writeOff(Invoice $invoice, User $actor, string $reason): Invoice
    {
        InvoiceStateMachine::assertIn($invoice->status, [InvoiceStatus::Open, InvoiceStatus::PartiallyPaid, InvoiceStatus::Disputed]);

        return DB::transaction(function () use ($invoice, $actor, $reason): Invoice {
            $invoice->forceFill([
                'status' => InvoiceStatus::Uncollectible,
                'written_off_at' => $this->clock->now(),
                'write_off_reason' => $reason,
                'status_before_dispute' => null,
            ])->save();
            $this->settlement->closed($invoice, LinkDeactivationReason::WrittenOff, ReminderSkipReason::InvoiceUncollectible);
            $this->timeline->record($invoice, InvoiceEventType::WrittenOff, ['balance_minor' => $invoice->balanceDue()], $actor);
            $this->auditor->record('payflow.invoice.written_off', $invoice, metadata: ['reason' => $reason, 'balance_minor' => $invoice->balanceDue()], actor: $actor, workspace: $invoice->workspace_id, subjectLabel: $invoice->label());
            $this->settlement->refreshRisk($invoice);

            return $invoice->refresh();
        });
    }

    public function pause(Invoice $invoice, User $actor, bool $paused, ?string $until, ?string $reason): Invoice
    {
        InvoiceStateMachine::assertIn($invoice->status, [InvoiceStatus::Open, InvoiceStatus::PartiallyPaid, InvoiceStatus::Disputed, InvoiceStatus::Draft]);

        return DB::transaction(function () use ($invoice, $actor, $paused, $until, $reason): Invoice {
            if ($paused) {
                $invoice->forceFill([
                    'reminders_paused' => true,
                    'reminders_paused_until' => $until,
                    'reminders_pause_source' => InvoicePauseSource::Manual,
                ])->save();
                $this->reminders->forInvoice($invoice, ReminderSkipReason::InvoicePaused);
                $this->timeline->record($invoice, InvoiceEventType::RemindersPaused, ['until' => $until, 'reason' => $reason], $actor);
                $this->auditor->record('payflow.invoice.reminders_paused', $invoice, metadata: ['until' => $until, 'reason' => $reason], actor: $actor, workspace: $invoice->workspace_id, subjectLabel: $invoice->label());
            } else {
                $source = $invoice->reminders_pause_source;
                $invoice->forceFill(['reminders_paused' => false, 'reminders_paused_until' => null, 'reminders_pause_source' => null])->save();
                $this->timeline->record($invoice, InvoiceEventType::RemindersResumed, ['source' => $source?->value], $actor);
                $this->auditor->record('payflow.invoice.reminders_activated', $invoice, metadata: ['source' => $source?->value], actor: $actor, workspace: $invoice->workspace_id, subjectLabel: $invoice->label());
            }

            return $invoice->refresh();
        });
    }

    public function confirmFirstContact(Invoice $invoice, User $actor): Invoice
    {
        if ($invoice->first_contact_confirmed_at === null) {
            $invoice->forceFill(['first_contact_confirmed_at' => $this->clock->now(), 'first_contact_confirmed_by' => $actor->id])->save();
            $this->auditor->record('payflow.invoice.first_contact_confirmed', $invoice, actor: $actor, workspace: $invoice->workspace_id, subjectLabel: $invoice->label());
        }

        return $invoice;
    }

    public function acknowledgeReportedPayment(Invoice $invoice, User $actor, bool $resume): Invoice
    {
        return DB::transaction(function () use ($invoice, $actor, $resume): Invoice {
            $this->alerts->resolve($invoice->workspace_id, 'crp:'.$invoice->id, $actor);

            if ($resume && $invoice->reminders_pause_source === InvoicePauseSource::ClientReportedPaid) {
                $invoice->forceFill(['reminders_paused' => false, 'reminders_paused_until' => null, 'reminders_pause_source' => null])->save();
                $this->timeline->record($invoice, InvoiceEventType::RemindersResumed, ['source' => InvoicePauseSource::ClientReportedPaid->value], $actor);
            }

            $this->auditor->record('payflow.alert.resolved', $invoice, metadata: ['dedupe_key' => 'crp:'.$invoice->id, 'resume' => $resume], actor: $actor, workspace: $invoice->workspace_id, subjectLabel: $invoice->label());

            return $invoice->refresh();
        });
    }

    public function applyLateFee(Invoice $invoice, User $actor, ?int $amountMinor): Invoice
    {
        InvoiceStateMachine::assertIn($invoice->status, [InvoiceStatus::Open, InvoiceStatus::PartiallyPaid]);
        $today = WorkspaceClock::todayIn($invoice->workspace->timezone, $this->clock->now())->toDateString();
        $suggestion = LateFees::suggest($invoice, $today);
        $amount = $amountMinor ?? ($suggestion !== null ? $suggestion->amountMinor : 0);

        if ($amount <= 0) {
            throw new PayflowException('no_late_fee', 422);
        }

        $days = $suggestion !== null ? $suggestion->chargeableDays : max(0, BusinessDate::diff($invoice->dueDate(), $today));

        return DB::transaction(function () use ($invoice, $actor, $amount, $days): Invoice {
            $description = (string) trans('payflow.late_fee.item_description', ['days' => $days], $invoice->locale);
            InvoiceItems::add($invoice, InvoiceItemKind::LateFee, $description, '1', $amount, 0);
            $invoice->refresh();
            $this->timeline->record($invoice, InvoiceEventType::LateFeeApplied, ['amount_minor' => $amount, 'days' => $days], $actor);
            $this->auditor->record('payflow.invoice.late_fee_applied', $invoice, metadata: ['amount_minor' => $amount, 'currency' => $invoice->currency], actor: $actor, workspace: $invoice->workspace_id, subjectLabel: $invoice->label());
            $this->settlement->settle($invoice, $actor, false);

            if ($invoice->isOpen()) {
                $this->links->ensureActive($invoice);
            }

            return $invoice->refresh();
        });
    }

    public function recreateLink(Invoice $invoice): ?InvoicePaymentLink
    {
        InvoiceStateMachine::assertIn($invoice->status, [InvoiceStatus::Open, InvoiceStatus::PartiallyPaid]);

        if ($this->links->linkKind($invoice) === PaymentLinkKind::Single) {
            throw new PayflowException('link_created_on_pay', 409);
        }

        if ($this->links->payableReason($invoice) !== null) {
            throw new PayflowException('not_payable', 409);
        }

        $this->links->deactivate($invoice, LinkDeactivationReason::Manual);

        return $this->links->ensureActive($invoice);
    }
}
