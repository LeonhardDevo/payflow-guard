<?php

declare(strict_types=1);

namespace App\Domain\Shared\Time;

use Carbon\CarbonImmutable;

interface Clock
{
    public function now(): CarbonImmutable;
}
