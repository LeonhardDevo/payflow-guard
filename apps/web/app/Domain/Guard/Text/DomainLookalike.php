<?php

declare(strict_types=1);

namespace App\Domain\Guard\Text;

final class DomainLookalike
{
    private const array SKELETON = ['rn' => 'm', 'vv' => 'w', 'cl' => 'd', '0' => 'o', '1' => 'l', 'i' => 'l', '5' => 's', '3' => 'e'];

    public static function isLookalike(string $requesterDomain, string $knownDomain): bool
    {
        $a = strtolower(trim($requesterDomain));
        $b = strtolower(trim($knownDomain));

        if ($a === '' || $b === '' || $a === $b) {
            return false;
        }

        if (str_starts_with($a, 'xn--') || str_contains($a, '.xn--')) {
            return true;
        }

        [$labelA, $tldA] = self::split($a);
        [$labelB, $tldB] = self::split($b);

        if ($labelA === '' || $labelB === '') {
            return false;
        }

        if ($labelA === $labelB) {
            return $tldA !== $tldB;
        }

        $threshold = strlen($labelB) < 5 ? 1 : 2;

        if (self::damerauLevenshtein($labelA, $labelB) <= $threshold) {
            return true;
        }

        if (self::skeleton($labelA) === self::skeleton($labelB)) {
            return true;
        }

        return strlen($labelB) >= 5 && str_contains($labelA, $labelB);
    }

    public static function isFreeMail(string $domain): bool
    {
        /** @var list<string> $domains */
        $domains = config('payflow.free_mail_domains', []);

        return in_array(strtolower($domain), $domains, true);
    }

    /** @return array{0: string, 1: string} */
    public static function split(string $domain): array
    {
        $labels = explode('.', $domain);

        if (count($labels) < 2) {
            return [$domain, ''];
        }

        /** @var list<string> $twoLevel */
        $twoLevel = config('payflow.two_level_tlds', []);
        $lastTwo = implode('.', array_slice($labels, -2));

        if (count($labels) >= 3 && in_array($lastTwo, $twoLevel, true)) {
            return [$labels[count($labels) - 3], $lastTwo];
        }

        return [$labels[count($labels) - 2], $labels[count($labels) - 1]];
    }

    public static function skeleton(string $label): string
    {
        return strtr($label, self::SKELETON);
    }

    public static function damerauLevenshtein(string $a, string $b): int
    {
        $lengthA = strlen($a);
        $lengthB = strlen($b);
        $d = [];

        for ($i = 0; $i <= $lengthA; $i++) {
            $d[$i][0] = $i;
        }

        for ($j = 0; $j <= $lengthB; $j++) {
            $d[0][$j] = $j;
        }

        for ($i = 1; $i <= $lengthA; $i++) {
            for ($j = 1; $j <= $lengthB; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);

                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $d[$lengthA][$lengthB];
    }
}
