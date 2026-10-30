<?php

declare(strict_types=1);

namespace App\Domain\Guard\Enums;

use App\Domain\Shared\Enums\HasValues;

enum AlertType: string
{
    use HasValues;

    case BankChangeRequested = 'bank_change_requested';
    case BankChangeHighRisk = 'bank_change_high_risk';
    case SharedBankAccount = 'shared_bank_account';
    case VendorPhoneChanged = 'vendor_phone_changed';
    case PayoutPendingApproval = 'payout_pending_approval';
    case PayoutHighRisk = 'payout_high_risk';
    case PayoutOnHold = 'payout_on_hold';
    case PayoutStaleApproval = 'payout_stale_approval';
    case InsufficientApprovers = 'insufficient_approvers';
    case GuardSettingsChanged = 'guard_settings_changed';
    case GuardRoleGranted = 'guard_role_granted';
    case GuardRoleRevoked = 'guard_role_revoked';
    case SecondFactorMissing = 'second_factor_missing';
    case TrustedContactChanged = 'trusted_contact_changed';
    case ReceivingDetailsChangeRequested = 'receiving_details_change_requested';
    case ReceivingDetailsChanged = 'receiving_details_changed';
    case CollectionsEscalation = 'collections_escalation';
    case ReminderFailed = 'reminder_failed';
    case RemindersOptedOut = 'reminders_opted_out';
    case ClientReportedPaid = 'client_reported_paid';
    case PaymentProviderError = 'payment_provider_error';
    case PaymentAccountRestricted = 'payment_account_restricted';
}
