<?php

declare(strict_types=1);

namespace App\Domain\Collections\Planning;

use App\Domain\Collections\Enums\ReminderSkipReason;
use App\Domain\Receivables\Enums\ClientPauseSource;
use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Time\BusinessDate;
use Carbon\CarbonInterface;

final class ReminderEligibility
{
    public static function check(Invoice $invoice, CarbonInterface|string $today, bool $ignorePause = false): ?ReminderSkipReason
    {
        $today = BusinessDate::of($today);
        $statusReason = match ($invoice->status) {
            InvoiceStatus::Paid => ReminderSkipReason::InvoicePaid,
            InvoiceStatus::Void, InvoiceStatus::Draft => ReminderSkipReason::InvoiceVoid,
            InvoiceStatus::Uncollectible => ReminderSkipReason::InvoiceUncollectible,
            InvoiceStatus::Disputed => ReminderSkipReason::InvoiceDisputed,
            default => null,
        };

        if ($statusReason !== null) {
            return $statusReason;
        }

        if ($invoice->balanceDue() <= 0) {
            return ReminderSkipReason::InvoicePaid;
        }

        if ($invoice->sent_at === null) {
            return ReminderSkipReason::InvoicePaused;
        }

        if (! $ignorePause && self::isPaused($invoice->reminders_paused, $invoice->reminders_paused_until, $today)) {
            return ReminderSkipReason::InvoicePaused;
        }

        $client = $invoice->client;
        $clientReason = self::clientReason($client, $today, $ignorePause);

        if ($clientReason !== null) {
            return $clientReason;
        }

        if ($client->email === null || trim($client->email) === '') {
            return ReminderSkipReason::NoRecipient;
        }

        return null;
    }

    public static function clientReason(Client $client, string $today, bool $ignoreManualPause = false): ?ReminderSkipReason
    {
        if (! self::isPaused($client->reminders_paused, $client->reminders_paused_until, $today)) {
            return null;
        }

        if ($client->reminders_pause_source === ClientPauseSource::RecipientOptOut) {
            return $ignoreManualPause ? null : ReminderSkipReason::RecipientOptOut;
        }

        return $ignoreManualPause ? null : ReminderSkipReason::ClientPaused;
    }

    public static function isPaused(bool $paused, ?CarbonInterface $until, string $today): bool
    {
        return $paused && ($until === null || $until->format('Y-m-d') >= $today);
    }
}
