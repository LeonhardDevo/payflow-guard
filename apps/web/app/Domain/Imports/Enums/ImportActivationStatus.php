<?php

declare(strict_types=1);

namespace App\Domain\Imports\Enums;

use App\Domain\Shared\Enums\HasValues;

enum ImportActivationStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Activated = 'activated';
    case Dismissed = 'dismissed';
}
