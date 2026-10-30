<?php

declare(strict_types=1);

namespace App\Domain\Guard\Enums;

use App\Domain\Shared\Enums\HasValues;

enum AlertStatus: string
{
    use HasValues;

    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';
}
