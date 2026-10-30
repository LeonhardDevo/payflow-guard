<?php

declare(strict_types=1);

namespace App\Policies\Payflow;

use App\Domain\Guard\Models\PayoutRequest;
use App\Enums\WorkspaceRole;
use App\Models\User;

final class PayoutPolicy
{
    use WorkspaceAbilities;

    public function view(User $user, PayoutRequest $payout): bool
    {
        return $this->allowed($user, $payout, 'payouts.view');
    }

    public function manage(User $user, PayoutRequest $payout): bool
    {
        return $this->allowed($user, $payout, 'payouts.create');
    }

    public function cancel(User $user, PayoutRequest $payout): bool
    {
        return $this->allowed($user, $payout, 'payouts.create')
            || ($this->allowed($user, $payout, 'payouts.view') && $user->roleIn($payout->workspace_id) === WorkspaceRole::Owner);
    }

    public function decide(User $user, PayoutRequest $payout): bool
    {
        return $this->allowed($user, $payout, 'payouts.approve');
    }

    public function release(User $user, PayoutRequest $payout): bool
    {
        return $this->allowed($user, $payout, 'payouts.release');
    }
}
