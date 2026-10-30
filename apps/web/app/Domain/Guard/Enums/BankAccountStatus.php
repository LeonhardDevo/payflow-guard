<?php

declare(strict_types=1);

namespace App\Domain\Guard\Enums;

use App\Domain\Shared\Enums\HasValues;

enum BankAccountStatus: string
{
    use HasValues;

    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
}
