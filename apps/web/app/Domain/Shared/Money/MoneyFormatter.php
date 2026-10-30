<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money;

final class MoneyFormatter
{
    private const string NBSP = "\u{00A0}";

    private const array EN_SYMBOLS = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'CAD' => 'CA$', 'AUD' => 'A$'];

    private const array RU_SYMBOLS = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'RUB' => '₽', 'CAD' => 'CA$', 'AUD' => 'A$'];

    public static function format(int $minor, string $currency, string $locale): string
    {
        $currency = strtoupper($currency);
        $sign = $minor < 0 ? '-' : '';
        $absolute = abs($minor);
        $whole = intdiv($absolute, 100);
        $fraction = str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        if ($locale === 'ru') {
            $number = self::group((string) $whole, self::NBSP).','.$fraction;

            return $sign.$number.self::NBSP.(self::RU_SYMBOLS[$currency] ?? $currency);
        }

        $number = self::group((string) $whole, ',').'.'.$fraction;
        $symbol = self::EN_SYMBOLS[$currency] ?? null;

        return $symbol !== null ? $sign.$symbol.$number : $sign.$currency.self::NBSP.$number;
    }

    /** @param  array{minor: int, currency: string}  $money */
    public static function formatArray(array $money, string $locale): string
    {
        return self::format($money['minor'], $money['currency'], $locale);
    }

    public static function decimal(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $absolute = abs($minor);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function group(string $digits, string $separator): string
    {
        $groups = [];

        while (strlen($digits) > 3) {
            array_unshift($groups, substr($digits, -3));
            $digits = substr($digits, 0, -3);
        }

        array_unshift($groups, $digits);

        return implode($separator, $groups);
    }
}
