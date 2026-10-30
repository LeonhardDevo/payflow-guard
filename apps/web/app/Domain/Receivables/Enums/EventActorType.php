<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Enums;

use App\Domain\Shared\Enums\HasValues;

enum EventActorType: string
{
    use HasValues;

    case User = 'user';
    case System = 'system';
    case Client = 'client';
    case Provider = 'provider';
}
