<?php

declare(strict_types=1);

namespace App\Domain\Collections\Enums;

use App\Domain\Shared\Enums\HasValues;

enum ReminderSkipReason: string
{
    use HasValues;

    case InvoicePaid = 'invoice_paid';
    case InvoiceVoid = 'invoice_void';
    case InvoiceUncollectible = 'invoice_uncollectible';
    case InvoiceDisputed = 'invoice_disputed';
    case InvoicePaused = 'invoice_paused';
    case ClientPaused = 'client_paused';
    case RecipientOptOut = 'recipient_opt_out';
    case NoRecipient = 'no_recipient';
    case Superseded = 'superseded';
    case LimitReached = 'limit_reached';
    case PolicyChanged = 'policy_changed';
}
