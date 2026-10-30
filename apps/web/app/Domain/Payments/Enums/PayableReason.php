<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

use App\Domain\Shared\Enums\HasValues;

enum PayableReason: string
{
    use HasValues;

    case NoAccount = 'no_account';
    case CurrencyNotSupported = 'currency_not_supported';
    case AccountRestricted = 'account_restricted';
    case NotOpen = 'not_open';
}
