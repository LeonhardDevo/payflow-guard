<?php

declare(strict_types=1);

namespace App\Domain\Collections\Dispatch;

use App\Audit\Auditor;
use App\Domain\Collections\Email\ClientEmailComposer;
use App\Domain\Collections\Email\RenderedEmail;
use App\Domain\Collections\Enums\ReminderKind;
use App\Domain\Collections\Enums\ReminderStatus;
use App\Domain\Collections\Enums\Tone;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Collections\Planning\ReminderEligibility;
use App\Domain\Collections\Tone\ToneContext;
use App\Domain\Notifications\Email\DomainMailer;
use App\Domain\Payments\Links\PaymentLinkService;
use App\Domain\Receivables\Enums\InvoicePauseSource;
use App\Domain\Receivables\LateFees\LateFees;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Exceptions\PayflowException;
use App\Domain\Shared\Money\MoneyData;
use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class ManualReminders
{
    public function __construct(
        private ReminderDispatcher $dispatcher,
        private ClientEmailComposer $composer,
        private DomainMailer $mailer,
        private PaymentLinkService $links,
        private Auditor $auditor,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function preview(Invoice $invoice, ?Tone $tone = null): array
    {
        $now = $this->clock->now();
        $today = WorkspaceClock::todayIn($invoice->workspace->timezone, $now);
        $suggestion = ToneContext::suggest($invoice, $today);
        $chosen = $tone ?? $suggestion->tone;
        $reasons = $tone === null || $tone === $suggestion->tone ? $suggestion->reasons : [['code' => 'step_fixed_tone', 'params' => ['tone' => $chosen->value]]];
        $fee = in_array($chosen, [Tone::Firm, Tone::Final], true) ? LateFees::suggest($invoice, $today) : null;
        $feeTotal = $fee !== null ? $fee->amountMinor + $fee->alreadyAppliedMinor : null;
        $email = $this->dispatcher->render($invoice, $chosen, $today, $feeTotal);
        $client = $invoice->client;

        return [
            'tone' => $chosen->value,
            'reasons' => $reasons,
            'subject' => $email->subject,
            'body_markdown' => $email->markdown,
            'body_html' => $email->html,
            'recipient' => $client->email,
            'cc' => $client->cc_emails,
            'late_fee' => $feeTotal !== null ? MoneyData::of($feeTotal, $invoice->currency) : null,
            'warnings' => $this->warnings($invoice, $now, $suggestion->tone),
        ];
    }

    /** @return list<string> */
    public function warnings(Invoice $invoice, CarbonImmutable $now, Tone $engineTone): array
    {
        $client = $invoice->client;
        $today = WorkspaceClock::todayIn($invoice->workspace->timezone, $now)->toDateString();
        $warnings = [];

        if ($client->email === null || $client->email === '') {
            $warnings[] = 'no_recipient';
        }

        if ($invoice->last_reminder_at !== null && $invoice->last_reminder_at->greaterThan($now->subDay())) {
            $warnings[] = 'recent_reminder';
        }

        if (ReminderEligibility::isPaused($invoice->reminders_paused, $invoice->reminders_paused_until, $today)
            || ($client->reminders_paused && ! $client->isOptedOut())) {
            $warnings[] = 'paused';
        }

        if ($client->isOptedOut()) {
            $warnings[] = 'recipient_opted_out';
        }

        if ($invoice->client_reported_paid_at !== null || $invoice->reminders_pause_source === InvoicePauseSource::ClientReportedPaid) {
            $warnings[] = 'client_reported_paid';
        }

        if ($this->links->payableReason($invoice) !== null) {
            $warnings[] = 'no_payment_link';
        }

        if ($invoice->reminders_sent_count === 0 && $invoice->first_contact_confirmed_at === null && $engineTone->isAbove(Tone::Neutral)) {
            $warnings[] = 'first_contact_capped';
        }

        return $warnings;
    }

    public function send(Invoice $invoice, User $actor, Tone $tone, ?string $subject, ?string $bodyMarkdown, bool $force): Reminder
    {
        $now = $this->clock->now();
        $client = $invoice->client;

        if ($client->isOptedOut() && ! $force) {
            throw new PayflowException('recipient_opted_out', 422);
        }

        if ($invoice->last_reminder_at !== null && $invoice->last_reminder_at->greaterThan($now->subDay()) && ! $force) {
            throw new PayflowException('recent_reminder', 422);
        }

        if ($bodyMarkdown !== null && ! self::containsInvoiceLink($bodyMarkdown, ClientEmailComposer::publicUrl($invoice))) {
            throw new PayflowException('missing_invoice_link', 422);
        }

        return DB::transaction(function () use ($invoice, $actor, $tone, $subject, $bodyMarkdown, $now): Reminder {
            $reminder = new Reminder;
            $reminder->forceFill([
                'workspace_id' => $invoice->workspace_id,
                'invoice_id' => $invoice->id,
                'client_id' => $invoice->client_id,
                'kind' => ReminderKind::Manual,
                'tone' => $tone,
                'tone_reasons' => [['code' => 'step_fixed_tone', 'params' => ['tone' => $tone->value]]],
                'locale' => $invoice->locale,
                'scheduled_for' => $now,
                'status' => ReminderStatus::Scheduled,
                'created_by' => $actor->id,
            ])->save();

            $result = $this->dispatcher->run($reminder, $now, true, $subject, $bodyMarkdown);

            if ($result['status'] !== 'queued') {
                throw new PayflowException('not_eligible', 422);
            }

            $this->auditor->record(
                'payflow.reminder.sent_manually',
                $reminder,
                metadata: ['invoice_id' => $invoice->id, 'tone' => $tone->value, 'custom' => $bodyMarkdown !== null || $subject !== null],
                actor: $actor,
                workspace: $invoice->workspace_id,
                subjectLabel: $invoice->label(),
            );

            return $reminder->refresh();
        });
    }

    public static function containsInvoiceLink(string $body, string $url): bool
    {
        return preg_match('/\{\{\s*(invoice_link|payment_link)\s*\}\}/', $body) === 1 || str_contains($body, $url);
    }

    /** @return array{status: string, reason?: string, scheduled_for?: string} */
    public function sendNow(Reminder $reminder, User $actor): array
    {
        $this->assertStatus($reminder, ReminderStatus::Scheduled);
        $now = $this->clock->now();

        return DB::transaction(function () use ($reminder, $actor, $now): array {
            $locked = Reminder::query()->whereKey($reminder->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($locked, ReminderStatus::Scheduled);
            $locked->forceFill(['scheduled_for' => $now])->save();
            $result = $this->dispatcher->run($locked, $now, false);

            $this->auditor->record('payflow.reminder.sent_manually', $locked, metadata: ['send_now' => true, 'result' => $result['status']], actor: $actor, workspace: $locked->workspace_id);

            return $result;
        });
    }

    public function cancel(Reminder $reminder, User $actor): void
    {
        $this->assertStatus($reminder, ReminderStatus::Scheduled);
        $reminder->forceFill(['status' => ReminderStatus::Canceled, 'policy_step_id' => null])->save();
        $this->auditor->record('payflow.reminder.canceled', $reminder, metadata: ['invoice_id' => $reminder->invoice_id], actor: $actor, workspace: $reminder->workspace_id);
    }

    public function retry(Reminder $reminder, User $actor): void
    {
        $this->assertStatus($reminder, ReminderStatus::Failed);
        $invoice = $reminder->invoice;

        if ($reminder->subject === null || $reminder->body_html === null || $reminder->recipient_email === null) {
            throw new PayflowException('not_eligible', 422);
        }

        DB::transaction(function () use ($reminder, $invoice, $actor): void {
            $email = $this->composer->envelope(
                $invoice,
                new RenderedEmail((string) $reminder->subject, '', '', (string) $reminder->body_html, (string) $reminder->body_text),
                ['type' => 'reminder', 'id' => $reminder->id],
                true,
                [ClientEmailComposer::pdfAttachment($invoice)],
                (string) $reminder->recipient_email,
                $reminder->cc_emails,
            );
            $message = $this->mailer->publish($email);

            $reminder->forceFill([
                'status' => ReminderStatus::Queued,
                'queued_at' => $this->clock->now(),
                'failed_at' => null,
                'error' => null,
                'attempts' => $reminder->attempts + 1,
                'outbox_message_id' => $message->id,
            ])->save();

            $this->auditor->record('payflow.reminder.retried', $reminder, metadata: ['invoice_id' => $invoice->id], actor: $actor, workspace: $reminder->workspace_id);
        });
    }

    private function assertStatus(Reminder $reminder, ReminderStatus $status): void
    {
        if ($reminder->status !== $status) {
            throw new PayflowException('invalid_transition', 409);
        }
    }
}
