<?php

declare(strict_types=1);

namespace App\Policies\Payflow;

use App\Domain\Guard\Models\VendorBankAccount;
use App\Models\User;

final class BankAccountPolicy
{
    use WorkspaceAbilities;

    public function view(User $user, VendorBankAccount $account): bool
    {
        return $this->allowed($user, $account, 'vendors.view') || $this->allowed($user, $account, 'bank_changes.verify');
    }

    public function verify(User $user, VendorBankAccount $account): bool
    {
        return $this->allowed($user, $account, 'bank_changes.verify');
    }

    public function reject(User $user, VendorBankAccount $account): bool
    {
        return $this->allowed($user, $account, 'bank_changes.verify')
            || ($account->requested_by_user_id === $user->id && $this->allowed($user, $account, 'bank_changes.request'));
    }
}
