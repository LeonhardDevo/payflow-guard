<?php

declare(strict_types=1);

namespace App\Enums;

enum AiChannel: string
{
    case Telegram = 'telegram';
    case Web = 'web';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $channel): string => $channel->value, self::cases());
    }
}
