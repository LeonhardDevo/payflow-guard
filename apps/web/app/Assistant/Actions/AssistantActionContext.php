<?php

declare(strict_types=1);

namespace App\Assistant\Actions;

use App\Enums\AiChannel;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

final readonly class AssistantActionContext
{
    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public string $type,
        public array $payload,
        public AiChannel $channel,
        public ?User $user,
        public ?Workspace $workspace,
        public ?WorkspaceRole $role,
        public string $locale,
        public ?int $telegramUserId = null,
        public ?int $chatId = null,
    ) {}

    public function allows(string $ability): bool
    {
        return $this->role?->allows($ability) ?? false;
    }
}
