<?php

declare(strict_types=1);

namespace App\Domain\Guard\Text;

final class NameNormalizer
{
    private const array CYRILLIC = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's',
        'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
        'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    private const array LATIN = [
        'ä' => 'a', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a', 'ą' => 'a',
        'ç' => 'c', 'č' => 'c', 'ć' => 'c', 'ď' => 'd', 'đ' => 'd',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ę' => 'e', 'ě' => 'e', 'ė' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'į' => 'i',
        'ł' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ñ' => 'n', 'ń' => 'n', 'ň' => 'n', 'ņ' => 'n',
        'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'ō' => 'o',
        'ř' => 'r', 'š' => 's', 'ś' => 's', 'ş' => 's', 'ť' => 't', 'ţ' => 't',
        'ü' => 'u', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ū' => 'u', 'ů' => 'u', 'ų' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z', 'ß' => 'ss', 'ģ' => 'g', 'ķ' => 'k',
    ];

    private const array LEGAL_FORMS = [
        'sp z o o', 'd o o', 'ooo', 'ip', 'ao', 'pao', 'llc', 'ltd', 'limited', 'inc', 'corp', 'co', 'gmbh', 'ag', 'sa', 'srl', 'bv',
        'oy', 'ou', 'uab', 'sia', 'as', 'ab', 'plc', 'doo', 'ie',
    ];

    public static function normalize(string $name): string
    {
        $value = mb_strtolower($name, 'UTF-8');
        $value = strtr($value, ['ё' => 'е']);
        $value = strtr($value, self::CYRILLIC);
        $value = strtr($value, self::LATIN);
        $value = (string) preg_replace('/[^a-z0-9]+/u', ' ', $value);
        $value = ' '.trim((string) preg_replace('/\s+/', ' ', $value)).' ';

        foreach (self::LEGAL_FORMS as $form) {
            $pattern = '/ '.preg_quote($form, '/').'(?= )/';

            while (preg_match($pattern, $value) === 1) {
                $value = (string) preg_replace($pattern, '', $value);
            }
        }

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
