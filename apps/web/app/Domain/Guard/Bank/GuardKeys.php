<?php

declare(strict_types=1);

namespace App\Domain\Guard\Bank;

use RuntimeException;

final class GuardKeys
{
    public const string BANK_FINGERPRINT = 'bank-fingerprint:v1';

    public const string RECEIVING_DETAILS = 'receiving-details:v1';

    public static function derive(string $purpose): string
    {
        return hash_hmac('sha256', 'payflow:'.$purpose, self::appKey(), true);
    }

    public static function mac(string $purpose, string $message): string
    {
        return hash_hmac('sha256', $message, self::derive($purpose));
    }

    private static function appKey(): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException('APP_KEY is not set.');
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded === false ? $key : $decoded;
        }

        return $key;
    }
}
