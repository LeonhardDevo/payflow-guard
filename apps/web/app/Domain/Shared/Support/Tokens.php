<?php

declare(strict_types=1);

namespace App\Domain\Shared\Support;

final class Tokens
{
    private const string ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function base62(int $length): string
    {
        $token = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < $length; $i++) {
            $token .= self::ALPHABET[random_int(0, $max)];
        }

        return $token;
    }

    public static function seeded(string $seed, int $length): string
    {
        $token = '';
        $counter = 0;

        while (strlen($token) < $length) {
            $bytes = hash('sha256', $seed.'|'.$counter, true);

            foreach (str_split($bytes) as $byte) {
                $value = ord($byte);

                if ($value < 248) {
                    $token .= self::ALPHABET[$value % 62];
                }

                if (strlen($token) === $length) {
                    break;
                }
            }

            $counter++;
        }

        return $token;
    }
}
