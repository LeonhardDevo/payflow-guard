<?php

declare(strict_types=1);

namespace App\Billing;

final class MoneyFormatter
{
    private const array SYMBOLS = ['USD' => '$', 'EUR' => '€', 'RUB' => '₽', 'GBP' => '£'];

    public static function format(int $minor, string $currency, string $locale): string
    {
        $currency = strtoupper($currency);
        $symbol = self::SYMBOLS[$currency] ?? $currency;
        $decimals = $minor % 100 === 0 ? 0 : 2;
        $negative = $minor < 0;
        $value = abs($minor) / 100;

        if ($locale === 'ru') {
            $number = number_format($value, $decimals, ',', "\u{00A0}");

            return ($negative ? '-' : '').$number."\u{00A0}".$symbol;
        }

        $number = number_format($value, $decimals, '.', ',');

        return ($negative ? '-' : '').(isset(self::SYMBOLS[$currency]) && $currency !== 'RUB' ? $symbol.$number : $number."\u{00A0}".$symbol);
    }
}
