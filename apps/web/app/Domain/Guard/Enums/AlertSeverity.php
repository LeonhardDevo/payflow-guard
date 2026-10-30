<?php

declare(strict_types=1);

namespace App\Domain\Guard\Enums;

use App\Domain\Shared\Enums\HasValues;

enum AlertSeverity: string
{
    use HasValues;

    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
