<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeZone;

final class Options
{
    /** @return list<string> */
    public static function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    /** @return list<string> */
    public static function currencies(): array
    {
        /** @var list<string> $currencies */
        $currencies = config('product.workspace.currencies', ['USD']);

        return $currencies;
    }
}
