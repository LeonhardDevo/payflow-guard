<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Enums;

use App\Domain\Shared\Enums\HasValues;

enum InvoiceEventType: string
{
    use HasValues;

    case Created = 'created';
    case Updated = 'updated';
    case Issued = 'issued';
    case Sent = 'sent';
    case MarkedSent = 'marked_sent';
    case Viewed = 'viewed';
    case ReminderSent = 'reminder_sent';
    case ReminderDeferred = 'reminder_deferred';
    case ReminderFailed = 'reminder_failed';
    case ReminderSkipped = 'reminder_skipped';
    case PaymentReceived = 'payment_received';
    case PaymentFailed = 'payment_failed';
    case PaymentRefunded = 'payment_refunded';
    case PaymentReversed = 'payment_reversed';
    case LinkCreated = 'link_created';
    case LinkDeactivated = 'link_deactivated';
    case Disputed = 'disputed';
    case DisputeResolved = 'dispute_resolved';
    case LateFeeApplied = 'late_fee_applied';
    case Voided = 'voided';
    case WrittenOff = 'written_off';
    case RemindersPaused = 'reminders_paused';
    case RemindersResumed = 'reminders_resumed';
    case RemindersActivated = 'reminders_activated';
    case RecipientOptedOut = 'recipient_opted_out';
    case ClientReportedPaid = 'client_reported_paid';
    case Imported = 'imported';
    case RiskBandChanged = 'risk_band_changed';
    case Overpaid = 'overpaid';
    case Paid = 'paid';
}
