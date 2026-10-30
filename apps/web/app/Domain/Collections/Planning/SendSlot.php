<?php

declare(strict_types=1);

namespace App\Domain\Collections\Planning;

use App\Domain\Shared\Time\BusinessDate;
use Carbon\CarbonImmutable;

final class SendSlot
{
    public static function spread(string $publicToken): int
    {
        return crc32($publicToken) % 30;
    }

    public static function next(
        string $date,
        string $windowStart,
        string $windowEnd,
        bool $businessDaysOnly,
        string $timezone,
        CarbonImmutable $now,
        int $spreadMinutes,
    ): CarbonImmutable {
        $day = BusinessDate::of($date);

        for ($guard = 0; $guard < 400; $guard++) {
            if ($businessDaysOnly && BusinessDate::isWeekend($day)) {
                $day = BusinessDate::nextMonday($day);

                continue;
            }

            $start = BusinessDate::toUtc($day, $windowStart, $timezone)->addMinutes($spreadMinutes);
            $end = BusinessDate::toUtc($day, $windowEnd, $timezone);

            if ($now->lessThanOrEqualTo($start)) {
                return $start;
            }

            if ($now->lessThan($end)) {
                return self::ceilToMinute($now);
            }

            $day = BusinessDate::addDays($day, 1);
        }

        throw new \RuntimeException('No send slot found within 400 days.');
    }

    public static function ceilToMinute(CarbonImmutable $instant): CarbonImmutable
    {
        $floored = $instant->setTime((int) $instant->format('H'), (int) $instant->format('i'));

        return $floored->equalTo($instant) ? $floored : $floored->addMinute();
    }
}
