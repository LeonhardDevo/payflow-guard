<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Risk;

use App\Domain\Receivables\Enums\LatePayerClass;

final class ClientStatsCalculator
{
    public const int LATE_GRACE_DAYS = 3;

    /**
     * @param  list<int>  $daysLate
     * @return array{paid_invoices_count: int, late_invoices_count: int, avg_days_late: float, on_time_rate: float|null, class: LatePayerClass}
     */
    public static function compute(array $daysLate): array
    {
        $n = count($daysLate);
        $late = count(array_filter($daysLate, fn (int $days): bool => self::isLate($days)));
        $avg = $n > 0 ? array_sum($daysLate) / $n : 0.0;

        return [
            'paid_invoices_count' => $n,
            'late_invoices_count' => $late,
            'avg_days_late' => round($avg, 2),
            'on_time_rate' => $n > 0 ? round(1 - $late / $n, 4) : null,
            'class' => self::classify($n, $late, $avg),
        ];
    }

    public static function isLate(int $daysLate): bool
    {
        return $daysLate > self::LATE_GRACE_DAYS;
    }

    public static function classify(int $n, int $late, float $avg): LatePayerClass
    {
        if ($n < 3) {
            return LatePayerClass::New;
        }

        $share = $late / $n;

        return match (true) {
            $share <= 0.15 && $avg <= 3 => LatePayerClass::Reliable,
            $share >= 0.5 && $avg >= 10 => LatePayerClass::Chronic,
            default => LatePayerClass::Occasional,
        };
    }

    /** @param  list<int>  $daysLate */
    public static function expectedDelayDays(array $daysLate, float $workspaceMeanDaysLate): int
    {
        $n = count($daysLate);

        return (int) round((array_sum($daysLate) + 2 * $workspaceMeanDaysLate) / ($n + 2));
    }

    /** @param  list<float|int>  $values */
    public static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
