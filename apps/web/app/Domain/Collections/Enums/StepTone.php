<?php

declare(strict_types=1);

namespace App\Domain\Collections\Enums;

use App\Domain\Shared\Enums\HasValues;

enum StepTone: string
{
    use HasValues;

    case Auto = 'auto';
    case Friendly = 'friendly';
    case Neutral = 'neutral';
    case Firm = 'firm';
    case Final = 'final';
}
