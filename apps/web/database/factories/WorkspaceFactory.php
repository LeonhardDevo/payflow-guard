<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Workspace> */
class WorkspaceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'locale' => 'en',
        ];
    }

    public function withMember(User $user, WorkspaceRole $role = WorkspaceRole::Owner): static
    {
        return $this->afterCreating(function (Workspace $workspace) use ($user, $role): void {
            $workspace->addMember($user, $role);

            if ($user->current_workspace_id === null) {
                $user->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();
            }
        });
    }
}
