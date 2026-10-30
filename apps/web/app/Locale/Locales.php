<?php

declare(strict_types=1);

namespace App\Locale;

final class Locales
{
    public const string COOKIE = 'locale';

    private const array LABELS = [
        'en' => 'English',
        'ru' => 'Русский',
    ];

    /** @return list<string> */
    public static function supported(): array
    {
        /** @var list<string> $locales */
        $locales = config('app.supported_locales', ['en', 'ru']);

        return $locales;
    }

    public static function isSupported(?string $locale): bool
    {
        return is_string($locale) && in_array($locale, self::supported(), true);
    }

    public static function fallback(): string
    {
        $default = config('app.locale', 'en');

        return is_string($default) && self::isSupported($default) ? $default : self::supported()[0];
    }

    /** @return list<array{code: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (string $code): array => ['code' => $code, 'label' => self::LABELS[$code] ?? strtoupper($code)],
            self::supported(),
        );
    }

    public static function negotiate(?string $acceptLanguage): ?string
    {
        if ($acceptLanguage === null || trim($acceptLanguage) === '') {
            return null;
        }

        $candidates = [];

        foreach (explode(',', $acceptLanguage) as $index => $part) {
            $segments = array_map('trim', explode(';', $part));
            $tag = strtolower($segments[0]);

            if ($tag === '' || $tag === '*') {
                continue;
            }

            $quality = 1.0;

            foreach (array_slice($segments, 1) as $parameter) {
                if (str_starts_with($parameter, 'q=')) {
                    $quality = (float) substr($parameter, 2);
                }
            }

            if ($quality <= 0) {
                continue;
            }

            $candidates[] = ['tag' => $tag, 'q' => $quality, 'i' => $index];
        }

        usort($candidates, fn (array $a, array $b): int => [$b['q'], $a['i']] <=> [$a['q'], $b['i']]);

        foreach ($candidates as $candidate) {
            $primary = explode('-', str_replace('_', '-', $candidate['tag']))[0];

            if (self::isSupported($primary)) {
                return $primary;
            }
        }

        return null;
    }
}
