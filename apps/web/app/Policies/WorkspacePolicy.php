<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

final class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): bool
    {
        return $user->belongsToWorkspace($workspace);
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $user->roleIn($workspace)?->allows('workspace.manage') ?? false;
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $user->roleIn($workspace)?->allows('workspace.delete') ?? false;
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $user->roleIn($workspace)?->allows('members.manage') ?? false;
    }

    public function manageBilling(User $user, Workspace $workspace): bool
    {
        return $user->roleIn($workspace)?->allows('billing.manage') ?? false;
    }
}
