<?php

declare(strict_types=1);

namespace App\Enums;

enum DocumentStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Indexed = 'indexed';
    case Failed = 'failed';

    public function isPending(): bool
    {
        return $this === self::Queued || $this === self::Processing;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }
}
