<?php

declare(strict_types=1);

namespace App\Domain\Guard\Enums;

use App\Domain\Shared\Enums\HasValues;

enum TrustedContactStatus: string
{
    use HasValues;

    case PendingVerification = 'pending_verification';
    case Active = 'active';
    case Revoked = 'revoked';
}
