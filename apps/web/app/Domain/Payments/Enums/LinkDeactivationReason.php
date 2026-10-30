<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

use App\Domain\Shared\Enums\HasValues;

enum LinkDeactivationReason: string
{
    use HasValues;

    case Paid = 'paid';
    case AmountChanged = 'amount_changed';
    case Voided = 'voided';
    case WrittenOff = 'written_off';
    case Disputed = 'disputed';
    case Manual = 'manual';
    case ProviderError = 'provider_error';
    case Expired = 'expired';
    case AccountDisconnected = 'account_disconnected';
    case AccountRestricted = 'account_restricted';
}
