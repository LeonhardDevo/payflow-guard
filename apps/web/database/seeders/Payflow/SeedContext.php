<?php

declare(strict_types=1);

namespace Database\Seeders\Payflow;

use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\FrozenClock;
use App\Domain\Shared\Time\WorkspaceClock;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

final class SeedContext
{
    public const string TIMEZONE = 'Europe/Riga';

    public readonly CarbonImmutable $realNow;

    public readonly string $today;

    private FrozenClock $clock;

    public function __construct()
    {
        $this->realNow = CarbonImmutable::now('UTC');
        $this->today = WorkspaceClock::todayIn(self::TIMEZONE, $this->realNow)->toDateString();
        $this->clock = new FrozenClock($this->realNow);
        app()->instance(Clock::class, $this->clock);
    }

    public function date(int $offsetDays): string
    {
        return CarbonImmutable::parse($this->today)->addDays($offsetDays)->toDateString();
    }

    public function at(int $offsetDays, string $time = '10:00', string $timezone = self::TIMEZONE): CarbonImmutable
    {
        return CarbonImmutable::parse($this->date($offsetDays).' '.$time, $timezone)->utc();
    }

    public function travel(CarbonImmutable $instant): void
    {
        $this->clock->travelTo($instant);
        Carbon::setTestNow($instant);
        CarbonImmutable::setTestNow($instant);
    }

    public function restore(): void
    {
        $this->clock->travelTo($this->realNow);
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    public function release(): void
    {
        $this->restore();
        app()->forgetInstance(Clock::class);
    }
}
