<?php

declare(strict_types=1);

namespace App\Policies\Payflow;

use App\Models\User;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Database\Eloquent\Model;

trait WorkspaceAbilities
{
    private function allowed(User $user, Model $model, string $ability): bool
    {
        $workspaceId = $model->getAttribute('workspace_id');

        return is_int($workspaceId)
            && app(CurrentWorkspace::class)->id() === $workspaceId
            && $user->belongsToWorkspace($workspaceId)
            && $user->can($ability);
    }
}
