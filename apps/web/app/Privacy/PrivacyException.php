<?php

declare(strict_types=1);

namespace App\Privacy;

use RuntimeException;

final class PrivacyException extends RuntimeException
{
    /** @param  array<string, mixed>  $replace */
    public function __construct(public readonly string $reason, public readonly array $replace = [])
    {
        parent::__construct("Privacy rule violated: {$reason}");
    }

    public function userMessage(): string
    {
        return (string) __("notifications.privacy.{$this->reason}", $this->replace);
    }
}
