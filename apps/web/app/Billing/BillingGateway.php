<?php

declare(strict_types=1);

namespace App\Billing;

use App\Enums\BillingInterval;
use App\Models\User;
use App\Models\Workspace;

interface BillingGateway
{
    public function driver(): string;

    /** @throws BillingException */
    public function createCheckoutSession(
        Workspace $workspace,
        User $user,
        string $plan,
        BillingInterval $interval,
        string $successUrl,
        string $cancelUrl,
    ): string;

    /** @throws BillingException */
    public function createPortalSession(Workspace $workspace, string $returnUrl): ?string;
}
