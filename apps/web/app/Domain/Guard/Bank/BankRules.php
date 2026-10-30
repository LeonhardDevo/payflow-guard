<?php

declare(strict_types=1);

namespace App\Domain\Guard\Bank;

final class BankRules
{
    public const string SWIFT_PATTERN = '/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/';

    public static function normalizeIban(string $iban): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', $iban));
    }

    public static function normalizeAccount(string $value): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', $value));
    }

    public static function ibanValid(string $iban): bool
    {
        $iban = self::normalizeIban($iban);

        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{8,30}$/', $iban) !== 1) {
            return false;
        }

        /** @var array<string, int> $lengths */
        $lengths = config('payflow.iban_lengths', []);
        $country = substr($iban, 0, 2);

        if (! isset($lengths[$country]) || $lengths[$country] !== strlen($iban)) {
            return false;
        }

        return self::mod97(substr($iban, 4).substr($iban, 0, 4)) === 1;
    }

    public static function mod97(string $alphanumeric): int
    {
        $remainder = 0;

        foreach (str_split($alphanumeric) as $char) {
            $digits = ctype_alpha($char) ? (string) (ord($char) - 55) : $char;

            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder;
    }

    public static function abaValid(string $routing): bool
    {
        if (preg_match('/^\d{9}$/', $routing) !== 1) {
            return false;
        }

        $d = array_map('intval', str_split($routing));

        return (3 * ($d[0] + $d[3] + $d[6]) + 7 * ($d[1] + $d[4] + $d[7]) + ($d[2] + $d[5] + $d[8])) % 10 === 0;
    }

    public static function achAccountValid(string $account): bool
    {
        return preg_match('/^\d{4,17}$/', $account) === 1;
    }

    public static function localAccountValid(string $account): bool
    {
        return preg_match('/^[A-Z0-9]{4,34}$/', self::normalizeAccount($account)) === 1;
    }

    public static function swiftValid(string $swift): bool
    {
        return preg_match(self::SWIFT_PATTERN, strtoupper($swift)) === 1;
    }

    public static function maskIban(string $iban): string
    {
        $iban = self::normalizeIban($iban);
        $groups = max(1, (int) ceil(max(0, strlen($iban) - 8) / 4));

        return substr($iban, 0, 4).' '.implode(' ', array_fill(0, $groups, '••••')).' '.substr($iban, -4);
    }

    public static function maskSwift(?string $swift): ?string
    {
        if ($swift === null || $swift === '') {
            return null;
        }

        $swift = strtoupper($swift);

        return substr($swift, 0, 4).str_repeat('•', max(1, strlen($swift) - 6)).substr($swift, -2);
    }

    public static function maskTaxId(?string $taxId): ?string
    {
        if ($taxId === null || trim($taxId) === '') {
            return null;
        }

        $clean = (string) preg_replace('/\s+/', '', $taxId);

        return '•••• '.substr($clean, -4);
    }
}
