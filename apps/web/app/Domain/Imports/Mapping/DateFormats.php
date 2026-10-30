<?php

declare(strict_types=1);

namespace App\Domain\Imports\Mapping;

use DateTimeImmutable;

final class DateFormats
{
    public const array SUPPORTED = ['Y-m-d', 'd.m.Y', 'd/m/Y', 'm/d/Y'];

    public static function parse(string $value, string $format): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ]/', $value, $matches) === 1) {
            $value = $matches[1];
        }

        $date = DateTimeImmutable::createFromFormat('!'.$format, $value);

        if ($date === false) {
            $relaxed = DateTimeImmutable::createFromFormat('!'.str_replace(['d', 'm'], ['j', 'n'], $format), $value);
            $date = $relaxed !== false ? $relaxed : null;
        }

        if ($date === null) {
            return null;
        }

        $errors = DateTimeImmutable::getLastErrors();

        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    /** @param  list<string>  $samples */
    public static function detect(array $samples): string
    {
        $samples = array_values(array_filter(array_map('trim', $samples), fn (string $sample): bool => $sample !== ''));

        if ($samples === []) {
            return 'Y-m-d';
        }

        foreach (self::SUPPORTED as $format) {
            $ok = true;

            foreach ($samples as $sample) {
                if (self::parse($sample, $format) === null) {
                    $ok = false;
                    break;
                }
            }

            if ($ok) {
                return $format;
            }
        }

        return 'Y-m-d';
    }
}
