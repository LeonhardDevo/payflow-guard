<?php

declare(strict_types=1);

namespace App\Policies\Payflow;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class CollectionsConfigPolicy
{
    use WorkspaceAbilities;

    public function update(User $user, Model $model): bool
    {
        return $this->allowed($user, $model, 'reminders.configure');
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->allowed($user, $model, 'reminders.configure');
    }
}
