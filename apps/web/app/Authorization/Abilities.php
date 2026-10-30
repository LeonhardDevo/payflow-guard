<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Auth\Access\Gate;

final readonly class Abilities
{
    public const string ADMIN_AI = 'admin.ai';

    public function __construct(private CurrentWorkspace $currentWorkspace) {}

    /** @return list<string> */
    public static function workspaceAbilities(): array
    {
        /** @var list<string> $abilities */
        $abilities = config('permissions.abilities', []);

        return $abilities;
    }

    /** @return list<string> */
    public static function platformAbilities(): array
    {
        return [self::ADMIN_AI];
    }

    public function register(Gate $gate): void
    {
        foreach (self::workspaceAbilities() as $ability) {
            $gate->define($ability, fn (User $user): bool => $this->roleFor($user)?->allows($ability) ?? false);
        }

        $gate->define(self::ADMIN_AI, fn (User $user): bool => $user->is_platform_admin);
    }

    /** @return array<string, bool> */
    public function map(?User $user): array
    {
        $map = [];
        $role = $user !== null ? $this->roleFor($user) : null;

        foreach (self::workspaceAbilities() as $ability) {
            $map[$ability] = $role?->allows($ability) ?? false;
        }

        $map[self::ADMIN_AI] = $user !== null && $user->is_platform_admin;

        return $map;
    }

    private function roleFor(User $user): ?WorkspaceRole
    {
        $workspace = $this->currentWorkspace->get();

        if ($workspace === null) {
            return null;
        }

        $authId = auth()->id();

        return $authId === $user->id ? $this->currentWorkspace->role() : $user->roleIn($workspace);
    }
}
