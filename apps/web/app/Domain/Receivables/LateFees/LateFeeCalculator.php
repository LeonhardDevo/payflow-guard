<?php

declare(strict_types=1);

namespace App\Domain\Receivables\LateFees;

use App\Domain\Receivables\Enums\LateFeeType;

final class LateFeeCalculator
{
    public static function chargeableDays(LateFeeTerms $terms, int $daysOverdue): int
    {
        return max(0, $daysOverdue - $terms->graceDays);
    }

    public static function fee(LateFeeTerms $terms, int $balanceMinor, int $totalMinor, int $daysOverdue): int
    {
        $days = self::chargeableDays($terms, $daysOverdue);
        $balance = max(0, $balanceMinor);
        $rate = $terms->rateBp ?? 0;

        $fee = match ($terms->type) {
            LateFeeType::None => 0,
            LateFeeType::PercentMonthly => intdiv($balance * $rate * $days * 2 + 300000, 600000),
            LateFeeType::PercentDaily => intdiv($balance * $rate * $days * 2 + 10000, 20000),
            LateFeeType::Fixed => $days > 0 ? ($terms->fixedMinor ?? 0) : 0,
        };

        if ($terms->capBp !== null) {
            $fee = min($fee, intdiv($totalMinor * $terms->capBp * 2 + 10000, 20000));
        }

        return max(0, $fee);
    }

    public static function suggested(LateFeeTerms $terms, int $balanceExcludingFeesMinor, int $totalExcludingFeesMinor, int $daysOverdue, int $alreadyAppliedMinor): int
    {
        return max(0, self::fee($terms, $balanceExcludingFeesMinor, $totalExcludingFeesMinor, $daysOverdue) - $alreadyAppliedMinor);
    }
}
