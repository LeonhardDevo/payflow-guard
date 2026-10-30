<?php

declare(strict_types=1);

namespace App\Domain\Collections\Choices;

use App\Audit\Auditor;
use App\Domain\Collections\Dispatch\ReminderCanceller;
use App\Domain\Collections\Enums\ReminderSkipReason;
use App\Domain\Guard\Alerts\GuardAlerts;
use App\Domain\Guard\Enums\AlertSubjectType;
use App\Domain\Guard\Enums\AlertType;
use App\Domain\Notifications\Routing\ReceivablesNotifications;
use App\Domain\Receivables\Enums\ClientPauseSource;
use App\Domain\Receivables\Enums\EventActorType;
use App\Domain\Receivables\Enums\InvoiceEventType;
use App\Domain\Receivables\Enums\InvoicePauseSource;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Receivables\Timeline\InvoiceTimeline;
use App\Domain\Shared\Time\BusinessDate;
use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\WorkspaceClock;
use Illuminate\Support\Facades\DB;

final readonly class RecipientChoices
{
    public function __construct(
        private ReminderCanceller $canceller,
        private InvoiceTimeline $timeline,
        private GuardAlerts $alerts,
        private ReceivablesNotifications $notifications,
        private Auditor $auditor,
        private Clock $clock,
    ) {}

    public function reportPaid(Invoice $invoice, ?string $paidOn, ?string $reference): void
    {
        $now = $this->clock->now();
        $today = WorkspaceClock::todayIn($invoice->workspace->timezone, $now)->toDateString();

        DB::transaction(function () use ($invoice, $paidOn, $reference, $now, $today): void {
            $invoice->forceFill([
                'client_reported_paid_at' => $now,
                'client_reported_reference' => $reference !== null ? mb_substr($reference, 0, 140) : null,
            ]);

            if ($invoice->isOpen()) {
                $invoice->forceFill([
                    'reminders_paused' => true,
                    'reminders_paused_until' => BusinessDate::addDays($today, 7),
                    'reminders_pause_source' => InvoicePauseSource::ClientReportedPaid,
                ]);
            }

            $invoice->save();
            $this->canceller->forInvoice($invoice, ReminderSkipReason::InvoicePaused);
            $this->timeline->record($invoice, InvoiceEventType::ClientReportedPaid, ['paid_on' => $paidOn, 'reference' => $reference], actorType: EventActorType::Client, at: $now);

            $this->alerts->ensure(
                $invoice->workspace_id,
                AlertType::ClientReportedPaid,
                AlertSubjectType::Invoice,
                $invoice->id,
                'crp:'.$invoice->id,
                ['number' => $invoice->label(), 'client' => $invoice->client->name, 'reference' => $reference],
            );

            $this->auditor->record('payflow.recipient.reported_paid', $invoice, metadata: ['paid_on' => $paidOn], workspace: $invoice->workspace_id, subjectLabel: $invoice->label(), system: true);
            $this->notifications->clientReportedPaid($invoice);
        });
    }

    public function stop(Invoice $invoice, ?string $note): bool
    {
        $client = $invoice->client;

        if ($client->isOptedOut()) {
            return false;
        }

        $now = $this->clock->now();

        DB::transaction(function () use ($invoice, $client, $note, $now): void {
            $client->forceFill([
                'reminders_paused' => true,
                'reminders_pause_source' => ClientPauseSource::RecipientOptOut,
                'reminders_paused_until' => null,
                'opted_out_at' => $now,
                'pause_reason' => $note !== null ? mb_substr($note, 0, 255) : $client->pause_reason,
            ])->save();

            $this->canceller->forClient($client, ReminderSkipReason::RecipientOptOut);

            Invoice::query()->forWorkspace($client->workspace_id)
                ->where('client_id', $client->id)
                ->whereIn('status', Invoice::OUTSTANDING_STATUSES)
                ->get()
                ->each(fn (Invoice $open) => $this->timeline->record($open, InvoiceEventType::RecipientOptedOut, ['source_invoice_id' => $invoice->id], actorType: EventActorType::Client, at: $now));

            $this->alerts->ensure(
                $client->workspace_id,
                AlertType::RemindersOptedOut,
                AlertSubjectType::Client,
                $client->id,
                'opt:'.$client->id,
                ['client' => $client->name],
            );

            $this->auditor->record('payflow.recipient.opted_out', $client, metadata: ['invoice_id' => $invoice->id], workspace: $client->workspace_id, subjectLabel: $client->name, system: true);
            $this->notifications->remindersOptedOut($client);
        });

        return true;
    }

    public static function optedOutAt(Client $client): ?string
    {
        return $client->isOptedOut() ? $client->opted_out_at?->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }
}
