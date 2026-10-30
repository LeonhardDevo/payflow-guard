<?php

declare(strict_types=1);

namespace App\Domain\Guard\Text;

final class TrigramSimilarity
{
    public static function similarity(string $a, string $b): float
    {
        return self::raw(NameNormalizer::normalize($a), NameNormalizer::normalize($b));
    }

    public static function raw(string $a, string $b): float
    {
        $left = self::trigrams($a);
        $right = self::trigrams($b);

        if ($left === [] || $right === []) {
            return 0.0;
        }

        $intersection = count(array_intersect_key($left, $right));
        $union = count($left + $right);

        return $intersection / $union;
    }

    /** @return array<string, true> */
    public static function trigrams(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $set = [];

        foreach ($words === false ? [] : $words as $word) {
            $padded = '  '.$word.' ';
            $length = strlen($padded);

            for ($i = 0; $i <= $length - 3; $i++) {
                $set[substr($padded, $i, 3)] = true;
            }
        }

        return $set;
    }
}
