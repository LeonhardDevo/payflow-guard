<?php

declare(strict_types=1);

namespace App\Policies\Payflow;

use App\Domain\Guard\Models\GuardAlert;
use App\Models\User;

final class GuardAlertPolicy
{
    use WorkspaceAbilities;

    public function handle(User $user, GuardAlert $alert): bool
    {
        return $this->allowed($user, $alert, 'guard.alerts');
    }
}
