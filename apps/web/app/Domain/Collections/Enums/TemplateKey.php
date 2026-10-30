<?php

declare(strict_types=1);

namespace App\Domain\Collections\Enums;

use App\Domain\Shared\Enums\HasValues;

enum TemplateKey: string
{
    use HasValues;

    case InvoiceIssued = 'invoice_issued';
    case ReminderFriendly = 'reminder_friendly';
    case ReminderNeutral = 'reminder_neutral';
    case ReminderFirm = 'reminder_firm';
    case ReminderFinal = 'reminder_final';
    case PaymentReceipt = 'payment_receipt';
}
