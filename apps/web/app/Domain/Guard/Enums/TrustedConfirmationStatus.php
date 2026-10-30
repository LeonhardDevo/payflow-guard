<?php

declare(strict_types=1);

namespace App\Domain\Guard\Enums;

use App\Domain\Shared\Enums\HasValues;

enum TrustedConfirmationStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Declined = 'declined';
    case Expired = 'expired';
    case Canceled = 'canceled';
}
