<?php

declare(strict_types=1);

namespace App\Assistant\Actions;

final readonly class AssistantActionResult
{
    public function __construct(
        public bool $ok,
        public ?string $message = null,
        public ?string $messageHtml = null,
        public bool $alert = false,
    ) {}

    public static function success(?string $message = null, ?string $messageHtml = null): self
    {
        return new self(true, $message, $messageHtml);
    }

    public static function failure(string $message, bool $alert = true): self
    {
        return new self(false, $message, null, $alert);
    }

    /** @return array{ok: bool, message?: string, message_html?: string, alert?: bool} */
    public function toArray(): array
    {
        return array_filter([
            'ok' => $this->ok,
            'message' => $this->message !== null ? mb_substr($this->message, 0, 190) : null,
            'message_html' => $this->messageHtml,
            'alert' => $this->alert ?: null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
