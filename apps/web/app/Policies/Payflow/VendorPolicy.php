<?php

declare(strict_types=1);

namespace App\Policies\Payflow;

use App\Domain\Guard\Models\Vendor;
use App\Models\User;

final class VendorPolicy
{
    use WorkspaceAbilities;

    public function view(User $user, Vendor $vendor): bool
    {
        return $this->allowed($user, $vendor, 'vendors.view');
    }

    public function update(User $user, Vendor $vendor): bool
    {
        return $this->allowed($user, $vendor, 'vendors.manage');
    }

    public function requestChange(User $user, Vendor $vendor): bool
    {
        return $this->allowed($user, $vendor, 'bank_changes.request');
    }

    public function reveal(User $user, Vendor $vendor): bool
    {
        return $this->allowed($user, $vendor, 'bank_details.reveal');
    }

    public function anonymise(User $user, Vendor $vendor): bool
    {
        return $this->allowed($user, $vendor, 'privacy.manage');
    }
}
