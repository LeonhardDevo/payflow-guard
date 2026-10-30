<?php

declare(strict_types=1);

namespace App\Policies\Payflow;

use App\Domain\Payments\Models\Payment;
use App\Models\User;

final class PaymentPolicy
{
    use WorkspaceAbilities;

    public function reverse(User $user, Payment $payment): bool
    {
        return $this->allowed($user, $payment, 'invoices.record_payment');
    }
}
