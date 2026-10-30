<?php

declare(strict_types=1);

namespace App\Workspaces\Contracts;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

interface RoleGrantGuard
{
    public function assertCanGrant(Workspace $workspace, User $actor, WorkspaceRole $role): void;

    public function granted(Workspace $workspace, User $member, ?WorkspaceRole $from, WorkspaceRole $to, User $actor): void;

    public function revoked(Workspace $workspace, User $member, WorkspaceRole $from, ?WorkspaceRole $to, User $actor): void;
}
