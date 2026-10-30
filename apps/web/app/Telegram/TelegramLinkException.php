<?php

declare(strict_types=1);

namespace App\Telegram;

use RuntimeException;

final class TelegramLinkException extends RuntimeException
{
    /** @param  'invalid_token'|'token_used'|'token_expired'|'already_linked'  $reason */
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Telegram link failed: {$reason}");
    }
}
