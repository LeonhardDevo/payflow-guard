<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Enums;

use App\Domain\Shared\Enums\HasValues;

enum AgingBucket: string
{
    use HasValues;

    case Current = 'current';
    case Days1To30 = 'd1_30';
    case Days31To60 = 'd31_60';
    case Days61To90 = 'd61_90';
    case Days90Plus = 'd90_plus';
}
