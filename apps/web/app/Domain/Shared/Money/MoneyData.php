<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money;

final class MoneyData
{
    /** @return array{minor: int, currency: string} */
    public static function of(int $minor, string $currency): array
    {
        return ['minor' => $minor, 'currency' => strtoupper($currency)];
    }

    /** @return array{minor: int, currency: string, formatted: string} */
    public static function formatted(int $minor, string $currency, string $locale): array
    {
        return [...self::of($minor, $currency), 'formatted' => MoneyFormatter::format($minor, $currency, $locale)];
    }

    /** @param  array<string, int>  $byCurrency */
    public static function joined(array $byCurrency, string $locale): string
    {
        $parts = [];

        foreach ($byCurrency as $currency => $minor) {
            $parts[] = MoneyFormatter::format($minor, (string) $currency, $locale);
        }

        return implode(' · ', $parts);
    }
}
