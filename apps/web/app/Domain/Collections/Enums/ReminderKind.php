<?php

declare(strict_types=1);

namespace App\Domain\Collections\Enums;

use App\Domain\Shared\Enums\HasValues;

enum ReminderKind: string
{
    use HasValues;

    case Scheduled = 'scheduled';
    case Manual = 'manual';
}
