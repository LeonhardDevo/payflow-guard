<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Enums;

use App\Domain\Shared\Enums\HasValues;

enum LateFeeType: string
{
    use HasValues;

    case None = 'none';
    case PercentMonthly = 'percent_monthly';
    case PercentDaily = 'percent_daily';
    case Fixed = 'fixed';
}
