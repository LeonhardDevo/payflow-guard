<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Enums;

use App\Domain\Shared\Enums\HasValues;

enum NotificationEvent: string
{
    use HasValues;

    case PaymentReceived = 'payment_received';
    case ReminderFailed = 'reminder_failed';
    case CollectionsEscalation = 'collections_escalation';
    case RemindersOptedOut = 'reminders_opted_out';
    case ClientReportedPaid = 'client_reported_paid';
    case BankChangeRequested = 'bank_change_requested';
    case BankChangeHighRisk = 'bank_change_high_risk';
    case PayoutPendingApproval = 'payout_pending_approval';
    case PayoutDecided = 'payout_decided';
    case PayoutReleased = 'payout_released';
    case GuardSettingsChanged = 'guard_settings_changed';
    case GuardRoleGranted = 'guard_role_granted';
    case ReceivingDetailsChange = 'receiving_details_change';
    case PaymentAccountIssue = 'payment_account_issue';
    case DailyDigest = 'daily_digest';
}
