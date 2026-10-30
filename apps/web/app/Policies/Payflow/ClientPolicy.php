<?php

declare(strict_types=1);

namespace App\Policies\Payflow;

use App\Domain\Receivables\Models\Client;
use App\Models\User;

final class ClientPolicy
{
    use WorkspaceAbilities;

    public function viewAny(User $user): bool
    {
        return $user->can('clients.view');
    }

    public function view(User $user, Client $client): bool
    {
        return $this->allowed($user, $client, 'clients.view');
    }

    public function create(User $user): bool
    {
        return $user->can('clients.manage');
    }

    public function update(User $user, Client $client): bool
    {
        return $this->allowed($user, $client, 'clients.manage');
    }

    public function delete(User $user, Client $client): bool
    {
        return $this->allowed($user, $client, 'clients.manage');
    }

    public function pause(User $user, Client $client): bool
    {
        return $this->allowed($user, $client, 'reminders.send');
    }

    public function anonymise(User $user, Client $client): bool
    {
        return $this->allowed($user, $client, 'privacy.manage');
    }
}
