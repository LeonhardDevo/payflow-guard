<?php

declare(strict_types=1);

namespace App\Billing;

use App\Enums\BillingInterval;
use App\Models\Subscription;
use App\Models\Workspace;

interface ManagesSubscriptions
{
    public function changePlan(Workspace $workspace, Subscription $subscription, string $plan, BillingInterval $interval): void;

    public function cancelAtPeriodEnd(Workspace $workspace, Subscription $subscription): void;

    public function resume(Workspace $workspace, Subscription $subscription): void;
}
