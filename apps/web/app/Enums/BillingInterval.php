<?php

declare(strict_types=1);

namespace App\Enums;

enum BillingInterval: string
{
    case Month = 'month';
    case Year = 'year';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $interval): string => $interval->value, self::cases());
    }
}
