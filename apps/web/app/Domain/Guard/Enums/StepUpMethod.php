<?php

declare(strict_types=1);

namespace App\Domain\Guard\Enums;

use App\Domain\Shared\Enums\HasValues;

enum StepUpMethod: string
{
    use HasValues;

    case Passkey = 'passkey';
    case Totp = 'totp';
    case Password = 'password';
    case None = 'none';
}
