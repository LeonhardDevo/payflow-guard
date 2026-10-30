<?php

declare(strict_types=1);

namespace App\Workspaces;

use RuntimeException;

final class WorkspaceException extends RuntimeException
{
    /** @param  array<string, mixed>  $replace */
    public function __construct(public readonly string $reason, public readonly array $replace = [])
    {
        parent::__construct("Workspace rule violated: {$reason}");
    }

    public function userMessage(): string
    {
        return (string) __("notifications.errors.{$this->reason}", $this->replace);
    }
}
