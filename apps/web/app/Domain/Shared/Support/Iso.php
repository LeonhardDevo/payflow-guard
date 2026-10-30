<?php

declare(strict_types=1);

namespace App\Domain\Shared\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class Iso
{
    public static function dateTime(?CarbonInterface $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    public static function date(?CarbonInterface $value): ?string
    {
        return $value?->format('Y-m-d');
    }
}
