<?php

declare(strict_types=1);

namespace App\Domain\Guard\Enums;

use App\Domain\Shared\Enums\HasValues;

enum ReceivingDetailsStatus: string
{
    use HasValues;

    case PendingConfirmation = 'pending_confirmation';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Superseded = 'superseded';
    case Rejected = 'rejected';
    case Canceled = 'canceled';
}
