<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Enums;

use App\Domain\Shared\Enums\HasValues;

enum ToneCeiling: string
{
    use HasValues;

    case None = 'none';
    case Neutral = 'neutral';
    case Firm = 'firm';
}
