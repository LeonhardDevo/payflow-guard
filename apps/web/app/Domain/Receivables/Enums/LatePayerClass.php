<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Enums;

use App\Domain\Shared\Enums\HasValues;

enum LatePayerClass: string
{
    use HasValues;

    case New = 'new';
    case Reliable = 'reliable';
    case Occasional = 'occasional';
    case Chronic = 'chronic';
}
