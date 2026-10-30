<?php

declare(strict_types=1);

namespace App\Domain\Collections\Email;

final readonly class RenderedEmail
{
    public function __construct(
        public string $subject,
        public string $markdown,
        public string $bodyHtml,
        public string $html,
        public string $text,
    ) {}
}
