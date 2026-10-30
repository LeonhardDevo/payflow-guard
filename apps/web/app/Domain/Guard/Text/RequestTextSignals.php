<?php

declare(strict_types=1);

namespace App\Domain\Guard\Text;

final class RequestTextSignals
{
    public const array URGENCY = [
        'urgent', 'urgently', 'asap', 'immediately', 'today', 'right away', 'as soon as possible', 'deadline', 'within the hour',
        'срочно', 'срочный', 'немедленно', 'сегодня же', 'как можно скорее', 'в течение часа', 'безотлагательно',
    ];

    public const array SECRECY = [
        'confidential', 'do not call', "don't call", 'no calls', 'keep this between', 'discreet', 'audit', 'travelling', 'traveling',
        "can't talk", 'cannot talk',
        'конфиденциально', 'не звоните', 'не звонить', 'не обсуждайте', 'в командировке', 'аудит', 'проверка', 'не могу говорить',
    ];

    public static function hasUrgency(string $text): bool
    {
        return self::matchesAny($text, self::URGENCY);
    }

    public static function hasSecrecy(string $text): bool
    {
        return self::matchesAny($text, self::SECRECY);
    }

    /** @param  list<string>  $phrases */
    public static function matchesAny(string $text, array $phrases): bool
    {
        $normalized = mb_strtolower(str_replace(['’', 'ё'], ["'", 'е'], $text), 'UTF-8');

        foreach ($phrases as $phrase) {
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote(mb_strtolower($phrase, 'UTF-8'), '/').'(?![\p{L}\p{N}])/u';

            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function phoneTails(string $text): array
    {
        preg_match_all('/(\+?\d[\d\s().-]{7,}\d)/', $text, $matches);
        $tails = [];

        foreach ($matches[1] as $phone) {
            $tail = self::phoneTail($phone);

            if ($tail !== null) {
                $tails[] = $tail;
            }
        }

        return array_values(array_unique($tails));
    }

    public static function phoneTail(?string $phone): ?string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $phone);

        return strlen($digits) >= 9 ? substr($digits, -9) : null;
    }

    public static function hasNewContact(string $text, ?string $knownPhone): bool
    {
        $known = self::phoneTail($knownPhone);

        foreach (self::phoneTails($text) as $tail) {
            if ($tail !== $known) {
                return true;
            }
        }

        return false;
    }
}
