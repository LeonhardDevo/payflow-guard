<?php

declare(strict_types=1);

namespace App\Domain\Guard\Enums;

use App\Domain\Shared\Enums\HasValues;

enum BankScheme: string
{
    use HasValues;

    case Iban = 'iban';
    case UsAch = 'us_ach';
    case Local = 'local';
}
