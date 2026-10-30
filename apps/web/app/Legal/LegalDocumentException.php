<?php

declare(strict_types=1);

namespace App\Legal;

use RuntimeException;

final class LegalDocumentException extends RuntimeException
{
    public static function unknownPlaceholder(string $placeholder, string $file): self
    {
        return new self("Unknown placeholder {{{$placeholder}}} in {$file}.");
    }

    public static function invalidFrontMatter(string $file, string $reason): self
    {
        return new self("Invalid front-matter in {$file}: {$reason}");
    }
}
