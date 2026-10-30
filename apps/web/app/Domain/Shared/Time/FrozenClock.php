<?php

declare(strict_types=1);

namespace App\Domain\Shared\Time;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class FrozenClock implements Clock
{
    private CarbonImmutable $now;

    public function __construct(CarbonInterface|string $now)
    {
        $this->now = CarbonImmutable::parse($now)->utc();
    }

    public function now(): CarbonImmutable
    {
        return $this->now;
    }

    public function travelTo(CarbonInterface|string $now): void
    {
        $this->now = CarbonImmutable::parse($now)->utc();
    }
}
