<?php

declare(strict_types=1);

namespace App\Domain\Collections\Dispatch;

use App\Domain\Collections\Email\ClientEmailComposer;
use App\Domain\Collections\Email\RenderedEmail;
use App\Domain\Collections\Enums\ReminderKind;
use App\Domain\Collections\Enums\ReminderSkipReason;
use App\Domain\Collections\Enums\ReminderStatus;
use App\Domain\Collections\Enums\StepTone;
use App\Domain\Collections\Enums\Tone;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Collections\Planning\ReminderEligibility;
use App\Domain\Collections\Planning\SendSlot;
use App\Domain\Collections\Tone\ToneContext;
use App\Domain\Notifications\Email\DomainMailer;
use App\Domain\Notifications\Routing\ReceivablesNotifications;
use App\Domain\Payments\Links\PaymentLinkService;
use App\Domain\Receivables\Clients\ClientTerms;
use App\Domain\Receivables\Enums\InvoiceEventType;
use App\Domain\Receivables\LateFees\LateFees;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Receivables\Timeline\InvoiceTimeline;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Time\BusinessDate;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final readonly class ReminderDispatcher
{
    public function __construct(
        private ClientEmailComposer $composer,
        private DomainMailer $mailer,
        private PaymentLinkService $links,
        private InvoiceTimeline $timeline,
        private ReceivablesNotifications $notifications,
    ) {}

    /** @return array{status: string, reason?: string, scheduled_for?: string, current?: string} */
    public function dispatch(int $reminderId, CarbonImmutable $expectedScheduledFor, CarbonImmutable $now): array
    {
        return DB::transaction(function () use ($reminderId, $expectedScheduledFor, $now): array {
            $reminder = Reminder::query()->withoutGlobalScopes()->whereKey($reminderId)->lockForUpdate()->firstOrFail();

            if ($reminder->status !== ReminderStatus::Scheduled) {
                return ['status' => 'already_processed', 'current' => $reminder->status->value];
            }

            if (! $reminder->scheduled_for->equalTo($expectedScheduledFor)) {
                return ['status' => 'rescheduled', 'scheduled_for' => self::iso($reminder->scheduled_for)];
            }

            if ($reminder->scheduled_for->greaterThan($now->addSeconds(60))) {
                return ['status' => 'too_early', 'scheduled_for' => self::iso($reminder->scheduled_for)];
            }

            return $this->run($reminder, $now, false);
        });
    }

    /** @return array{status: string, reason?: string, scheduled_for?: string} */
    public function run(Reminder $reminder, CarbonImmutable $now, bool $manual, ?string $subject = null, ?string $bodyMarkdown = null): array
    {
        $invoice = Invoice::query()->withoutGlobalScopes()->whereKey($reminder->invoice_id)->lockForUpdate()->firstOrFail();
        $workspace = Workspace::query()->findOrFail($invoice->workspace_id);
        $today = WorkspaceClock::todayIn($workspace->timezone, $now)->toDateString();
        $client = $invoice->client;
        $reason = ReminderEligibility::check($invoice, $today, $manual);

        if ($reason !== null) {
            return $this->skip($reminder, $invoice, $reason, $now);
        }

        $step = $reminder->step;
        $policy = $step !== null ? $step->policy : ClientTerms::policy($client);

        if (! $manual && $policy !== null && $invoice->reminders_sent_count >= $policy->max_reminders) {
            return $this->skip($reminder, $invoice, ReminderSkipReason::LimitReached, $now);
        }

        if (! $manual && $policy !== null && $invoice->last_reminder_at !== null
            && $invoice->last_reminder_at->greaterThan($now->subDays($policy->min_interval_days))) {
            $settings = WorkspaceProvisioner::settingsFor($workspace);
            $date = BusinessDate::addDays(WorkspaceClock::localDate($invoice->last_reminder_at, $workspace->timezone), $policy->min_interval_days);
            $earliest = CarbonImmutable::parse($invoice->last_reminder_at)->addDays($policy->min_interval_days);
            $slot = SendSlot::next(
                $date,
                $settings->windowStart(),
                $settings->windowEnd(),
                $settings->business_days_only,
                ClientTerms::sendTimezone($client, $workspace),
                $earliest->greaterThan($now) ? $earliest : $now,
                SendSlot::spread($invoice->public_token),
            );
            $reminder->forceFill(['scheduled_for' => $slot])->save();

            return ['status' => 'rescheduled', 'scheduled_for' => self::iso($slot)];
        }

        if ($manual) {
            $tone = $reminder->tone;
            $reasons = $reminder->tone_reasons;
        } else {
            $suggestion = ToneContext::suggest($invoice, $today, $step !== null ? $step->tone : StepTone::Auto);

            if ($reminder->is_first_contact && $reminder->kind === ReminderKind::Scheduled && $invoice->first_contact_confirmed_at === null) {
                $suggestion = $suggestion->withCap(Tone::Neutral, 'first_contact_cap');
            }

            $tone = $suggestion->tone;
            $reasons = $suggestion->reasons;
        }

        if ($step === null || $step->include_payment_link) {
            $this->links->ensureActive($invoice);
        }

        $lateFee = ($step !== null && $step->include_late_fee_notice) || ($manual && in_array($tone, [Tone::Firm, Tone::Final], true))
            ? LateFees::suggest($invoice, $today)
            : null;
        $lateFeeTotal = $lateFee !== null ? $lateFee->amountMinor + $lateFee->alreadyAppliedMinor : null;

        $email = $this->render($invoice, $tone, CarbonImmutable::parse($today), $lateFeeTotal, $subject, $bodyMarkdown);
        $attachments = $step === null || $step->attach_pdf ? [ClientEmailComposer::pdfAttachment($invoice)] : [];
        $envelope = $this->composer->envelope($invoice, $email, ['type' => 'reminder', 'id' => $reminder->id], true, $attachments);
        $message = $this->mailer->publish($envelope);

        $reminder->forceFill([
            'status' => ReminderStatus::Queued,
            'queued_at' => $now,
            'tone' => $tone,
            'tone_reasons' => $reasons,
            'subject' => $email->subject,
            'body_html' => $email->html,
            'body_text' => $email->text,
            'recipient_email' => $client->email,
            'cc_emails' => $client->cc_emails,
            'late_fee_quoted_minor' => $lateFeeTotal,
            'attempts' => $reminder->attempts + 1,
            'outbox_message_id' => $message->id,
            'skip_reason' => null,
        ])->save();

        $invoice->forceFill([
            'last_reminder_at' => $now,
            'reminders_sent_count' => $invoice->reminders_sent_count + 1,
        ])->save();

        if ($step !== null && $step->notify_owner) {
            $this->notifications->reminderEscalation($invoice, $reminder, $today);
        }

        return ['status' => 'queued'];
    }

    public function render(Invoice $invoice, Tone $tone, CarbonImmutable $today, ?int $lateFeeMinor, ?string $subject = null, ?string $bodyMarkdown = null): RenderedEmail
    {
        $variables = $this->composer->variables($invoice, $today, $lateFeeMinor);

        return $this->composer->compose($invoice, $tone->templateKey(), $variables, $subject, $bodyMarkdown);
    }

    /** @return array{status: string, reason: string} */
    private function skip(Reminder $reminder, Invoice $invoice, ReminderSkipReason $reason, CarbonImmutable $now): array
    {
        $reminder->forceFill(['status' => ReminderStatus::Skipped, 'skip_reason' => $reason, 'updated_at' => $now])->save();
        $this->timeline->record($invoice, InvoiceEventType::ReminderSkipped, ['reminder_id' => $reminder->id, 'reason' => $reason->value], at: $now);

        return ['status' => 'skipped', 'reason' => $reason->value];
    }

    private static function iso(CarbonInterface $instant): string
    {
        return CarbonImmutable::parse($instant)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
