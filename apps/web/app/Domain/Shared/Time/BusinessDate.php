<?php

declare(strict_types=1);

namespace App\Domain\Shared\Time;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class BusinessDate
{
    public static function of(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface ? $date->format('Y-m-d') : substr($date, 0, 10);
    }

    public static function diff(CarbonInterface|string $from, CarbonInterface|string $to): int
    {
        return intdiv(self::epoch(self::of($to)) - self::epoch(self::of($from)), 86400);
    }

    public static function addDays(CarbonInterface|string $date, int $days): string
    {
        return self::day($date)->addDays($days)->format('Y-m-d');
    }

    public static function isWeekend(CarbonInterface|string $date): bool
    {
        return self::day($date)->isWeekend();
    }

    public static function nextMonday(CarbonInterface|string $date): string
    {
        return self::day($date)->next(CarbonInterface::MONDAY)->format('Y-m-d');
    }

    public static function addBusinessDays(CarbonInterface|string $date, int $days): string
    {
        $current = self::of($date);

        while ($days > 0) {
            $current = self::addDays($current, 1);

            if (! self::isWeekend($current)) {
                $days--;
            }
        }

        return $current;
    }

    public static function max(string $a, string $b): string
    {
        return strcmp($a, $b) >= 0 ? $a : $b;
    }

    public static function toUtc(string $date, string $time, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse(self::of($date).' '.substr($time, 0, 5), WorkspaceClock::zone($timezone))->utc();
    }

    private static function epoch(string $date): int
    {
        return self::day($date)->getTimestamp();
    }

    public static function day(CarbonInterface|string $date): CarbonImmutable
    {
        return CarbonImmutable::parse(self::of($date).' 00:00:00', 'UTC');
    }
}
