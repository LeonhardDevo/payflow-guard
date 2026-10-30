<?php

declare(strict_types=1);

namespace App\Domain\Guard\Enums;

use App\Domain\Shared\Enums\HasValues;

enum VendorStatus: string
{
    use HasValues;

    case Active = 'active';
    case OnHold = 'on_hold';
    case Archived = 'archived';
}
