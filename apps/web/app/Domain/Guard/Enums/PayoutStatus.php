<?php

declare(strict_types=1);

namespace App\Domain\Guard\Enums;

use App\Domain\Shared\Enums\HasValues;

enum PayoutStatus: string
{
    use HasValues;

    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case OnHold = 'on_hold';
    case Released = 'released';
    case Rejected = 'rejected';
    case Canceled = 'canceled';
}
