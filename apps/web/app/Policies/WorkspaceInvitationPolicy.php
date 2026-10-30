<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WorkspaceInvitation;

final class WorkspaceInvitationPolicy
{
    public function delete(User $user, WorkspaceInvitation $invitation): bool
    {
        return $user->roleIn($invitation->workspace_id)?->allows('members.manage') ?? false;
    }
}
