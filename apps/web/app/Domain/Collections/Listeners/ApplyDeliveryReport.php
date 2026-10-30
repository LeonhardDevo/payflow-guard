<?php

declare(strict_types=1);

namespace App\Domain\Collections\Listeners;

use App\Domain\Collections\Enums\ReminderStatus;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Guard\Alerts\GuardAlerts;
use App\Domain\Guard\Enums\AlertSubjectType;
use App\Domain\Guard\Enums\AlertType;
use App\Domain\Notifications\Routing\ReceivablesNotifications;
use App\Domain\Receivables\Enums\EventActorType;
use App\Domain\Receivables\Enums\InvoiceEventType;
use App\Domain\Receivables\Timeline\InvoiceTimeline;
use App\Domain\Shared\Time\Clock;
use App\Events\DeliveryReported;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class ApplyDeliveryReport
{
    public function __construct(
        private InvoiceTimeline $timeline,
        private GuardAlerts $alerts,
        private ReceivablesNotifications $notifications,
        private Clock $clock,
    ) {}

    public function handle(DeliveryReported $event): void
    {
        $delivery = $event->delivery;

        if ($delivery->channel !== 'email') {
            return;
        }

        if ($delivery->reference_type !== 'reminder') {
            if (in_array($delivery->reference_type, ['invoice', 'payment_receipt'], true)) {
                Log::info('payflow.delivery', ['type' => $delivery->reference_type, 'id' => $delivery->reference_id, 'status' => $delivery->status]);
            }

            return;
        }

        if (! ctype_digit($delivery->reference_id)) {
            return;
        }

        DB::transaction(function () use ($delivery): void {
            $reminder = Reminder::query()->withoutGlobalScopes()->whereKey((int) $delivery->reference_id)->lockForUpdate()->first();

            if ($reminder === null || ! in_array($reminder->status, [ReminderStatus::Queued, ReminderStatus::Deferred], true)) {
                return;
            }

            $now = $this->clock->now();
            $invoice = $reminder->invoice;
            $attempt = $delivery->getAttribute('attempt');
            $nextRetry = $delivery->getAttribute('next_retry_at');

            match ($delivery->status) {
                'deferred' => $this->deferred($reminder, is_numeric($attempt) ? (int) $attempt : $reminder->delivery_attempts + 1, $nextRetry !== null ? CarbonImmutable::parse($nextRetry) : null, $delivery->error),
                'sent' => $this->sent($reminder, $now, $delivery->provider_message_id, is_numeric($attempt) ? (int) $attempt : null),
                'failed' => $this->failed($reminder, $now, $delivery->error, is_numeric($attempt) ? (int) $attempt : null),
                default => null,
            };

            $type = match ($delivery->status) {
                'deferred' => InvoiceEventType::ReminderDeferred,
                'sent' => InvoiceEventType::ReminderSent,
                'failed' => InvoiceEventType::ReminderFailed,
                default => null,
            };

            if ($type !== null) {
                $this->timeline->record($invoice, $type, ['reminder_id' => $reminder->id, 'tone' => $reminder->tone->value], actorType: EventActorType::System, at: $now);
            }
        });
    }

    private function deferred(Reminder $reminder, int $attempt, ?CarbonImmutable $nextRetry, ?string $error): void
    {
        $reminder->forceFill([
            'status' => ReminderStatus::Deferred,
            'delivery_attempts' => max($reminder->delivery_attempts, $attempt),
            'next_retry_at' => $nextRetry,
            'error' => $error !== null ? mb_substr($error, 0, 1000) : null,
        ])->save();
    }

    private function sent(Reminder $reminder, CarbonImmutable $now, ?string $providerMessageId, ?int $attempt): void
    {
        $reminder->forceFill([
            'status' => ReminderStatus::Sent,
            'sent_at' => $now,
            'provider_message_id' => $providerMessageId,
            'delivery_attempts' => max($reminder->delivery_attempts, $attempt ?? $reminder->delivery_attempts + 1),
            'next_retry_at' => null,
            'error' => null,
        ])->save();

        $this->alerts->resolve($reminder->workspace_id, 'rf:'.$reminder->id);
    }

    private function failed(Reminder $reminder, CarbonImmutable $now, ?string $error, ?int $attempt): void
    {
        $reminder->forceFill([
            'status' => ReminderStatus::Failed,
            'failed_at' => $now,
            'error' => $error !== null ? mb_substr($error, 0, 1000) : null,
            'delivery_attempts' => max($reminder->delivery_attempts, $attempt ?? $reminder->delivery_attempts + 1),
            'next_retry_at' => null,
        ])->save();

        $alert = $this->alerts->ensure(
            $reminder->workspace_id,
            AlertType::ReminderFailed,
            AlertSubjectType::Invoice,
            $reminder->invoice_id,
            'rf:'.$reminder->id,
            ['number' => $reminder->invoice->label(), 'client' => $reminder->client->name, 'reminder_id' => $reminder->id],
        );

        if ($alert->wasRecentlyCreated) {
            $this->notifications->reminderFailed($reminder);
        }
    }
}
