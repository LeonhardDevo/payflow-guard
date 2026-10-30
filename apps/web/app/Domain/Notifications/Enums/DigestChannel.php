<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Enums;

use App\Domain\Shared\Enums\HasValues;

enum DigestChannel: string
{
    use HasValues;

    case Telegram = 'telegram';
    case Email = 'email';
    case None = 'none';
}
