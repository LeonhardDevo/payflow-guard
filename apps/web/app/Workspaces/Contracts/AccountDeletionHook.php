<?php

declare(strict_types=1);

namespace App\Workspaces\Contracts;

use App\Models\User;

interface AccountDeletionHook
{
    public function mustPseudonymise(User $user): bool;
}
