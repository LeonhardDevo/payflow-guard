<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money;

use InvalidArgumentException;

final class MoneyParser
{
    public static function toMinor(string $decimal): int
    {
        $value = trim($decimal);

        if (preg_match('/^(-)?(\d{1,15})(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid decimal money value.');
        }

        $minor = ((int) $matches[2]) * 100 + (int) str_pad($matches[3] ?? '', 2, '0');

        return $matches[1] === '-' ? -$minor : $minor;
    }

    public static function isValid(string $decimal): bool
    {
        return preg_match('/^\d{1,15}(\.\d{1,2})?$/', trim($decimal)) === 1;
    }

    public static function percentToBasisPoints(string $percent): int
    {
        $value = trim($percent);

        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid percent value.');
        }

        return ((int) $matches[1]) * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    public static function basisPointsToPercent(?int $basisPoints): ?string
    {
        if ($basisPoints === null) {
            return null;
        }

        $whole = intdiv($basisPoints, 100);
        $fraction = $basisPoints % 100;

        return $fraction === 0 ? (string) $whole : rtrim($whole.'.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT), '0');
    }

    public static function lenient(string $raw): ?int
    {
        $value = trim(str_replace(["\u{00A0}", ' ', "'"], '', $raw));
        $value = (string) preg_replace('/[^\d,.\-]/', '', $value);

        if ($value === '' || $value === '-') {
            return null;
        }

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        if ($lastComma !== false && $lastDot !== false) {
            $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
        } elseif ($lastComma !== false) {
            $decimalSeparator = strlen($value) - $lastComma - 1 <= 2 ? ',' : null;
        } elseif ($lastDot !== false) {
            $decimalSeparator = substr_count($value, '.') === 1 && strlen($value) - $lastDot - 1 <= 2 ? '.' : null;
        } else {
            $decimalSeparator = null;
        }

        if ($decimalSeparator === null) {
            $normalized = str_replace([',', '.'], '', $value);
        } else {
            $thousands = $decimalSeparator === ',' ? '.' : ',';
            $normalized = str_replace([$thousands], '', $value);
            $normalized = str_replace($decimalSeparator, '.', $normalized);
        }

        if (preg_match('/^-?\d+(\.\d{1,2})?$/', $normalized) !== 1) {
            return null;
        }

        return self::toMinor($normalized);
    }
}
