<?php

declare(strict_types=1);

namespace App\Policies\Payflow;

use App\Domain\Imports\Models\Import;
use App\Models\User;

final class ImportPolicy
{
    use WorkspaceAbilities;

    public function view(User $user, Import $import): bool
    {
        return $this->allowed($user, $import, 'imports.run');
    }

    public function activate(User $user, Import $import): bool
    {
        return $this->allowed($user, $import, 'reminders.configure');
    }
}
