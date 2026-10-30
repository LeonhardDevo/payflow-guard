<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

final class PiiRedactor
{
    public static function text(string $text): string
    {
        $text = (string) preg_replace_callback(
            '/[A-Za-z0-9._%+\-]+@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})/u',
            fn (array $match): string => '•••@'.$match[1],
            $text,
        );
        $text = (string) preg_replace_callback(
            '/\b[A-Z]{2}\d{2}(?:\s?[A-Z0-9]{2,4}){3,8}\b/u',
            fn (array $match): string => '•••• '.substr((string) preg_replace('/\s+/', '', $match[0]), -4),
            $text,
        );

        return (string) preg_replace_callback(
            '/\+?\d[\d ().\-]{7,}\d/',
            function (array $match): string {
                $digits = (string) preg_replace('/\D+/', '', $match[0]);

                return strlen($digits) >= 9 ? '•••• '.substr($digits, -4) : $match[0];
            },
            $text,
        );
    }

    public static function value(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::text($value);
        }

        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                $result[$key] = in_array($key, ['url', 'iso', 'currency', 'minor', 'formatted', 'number'], true) ? $item : self::value($item);
            }

            return $result;
        }

        return $value;
    }
}
