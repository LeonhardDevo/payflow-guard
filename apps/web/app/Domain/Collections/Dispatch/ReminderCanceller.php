<?php

declare(strict_types=1);

namespace App\Domain\Collections\Dispatch;

use App\Domain\Collections\Enums\ReminderSkipReason;
use App\Domain\Collections\Enums\ReminderStatus;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Time\Clock;

final readonly class ReminderCanceller
{
    public function __construct(private Clock $clock) {}

    public function forInvoice(Invoice $invoice, ReminderSkipReason $reason): int
    {
        return Reminder::query()
            ->forWorkspace($invoice->workspace_id)
            ->where('invoice_id', $invoice->id)
            ->where('status', ReminderStatus::Scheduled->value)
            ->update([
                'status' => ReminderStatus::Canceled->value,
                'skip_reason' => $reason->value,
                'policy_step_id' => null,
                'updated_at' => $this->clock->now(),
            ]);
    }

    public function forClient(Client $client, ReminderSkipReason $reason): int
    {
        return Reminder::query()
            ->forWorkspace($client->workspace_id)
            ->where('client_id', $client->id)
            ->where('status', ReminderStatus::Scheduled->value)
            ->update([
                'status' => ReminderStatus::Canceled->value,
                'skip_reason' => $reason->value,
                'policy_step_id' => null,
                'updated_at' => $this->clock->now(),
            ]);
    }

    public static function reasonForStatus(string $status): ReminderSkipReason
    {
        return match ($status) {
            'paid' => ReminderSkipReason::InvoicePaid,
            'void' => ReminderSkipReason::InvoiceVoid,
            'uncollectible' => ReminderSkipReason::InvoiceUncollectible,
            'disputed' => ReminderSkipReason::InvoiceDisputed,
            default => ReminderSkipReason::PolicyChanged,
        };
    }
}
