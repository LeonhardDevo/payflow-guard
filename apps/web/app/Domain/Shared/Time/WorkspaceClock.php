<?php

declare(strict_types=1);

namespace App\Domain\Shared\Time;

use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final readonly class WorkspaceClock
{
    public function __construct(private Clock $clock) {}

    public function now(): CarbonImmutable
    {
        return $this->clock->now();
    }

    public function today(Workspace $workspace, ?CarbonInterface $now = null): CarbonImmutable
    {
        return self::todayIn($workspace->timezone, $now ?? $this->clock->now());
    }

    public static function todayIn(string $timezone, CarbonInterface $now): CarbonImmutable
    {
        return CarbonImmutable::parse($now)->setTimezone(self::zone($timezone))->startOfDay();
    }

    public static function localDate(CarbonInterface $instant, string $timezone): string
    {
        return CarbonImmutable::parse($instant)->setTimezone(self::zone($timezone))->toDateString();
    }

    public static function zone(?string $timezone): string
    {
        return is_string($timezone) && $timezone !== '' && in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
    }
}
