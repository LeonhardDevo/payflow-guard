<?php

declare(strict_types=1);

namespace App\Billing;

use RuntimeException;

final class PlanLimitExceeded extends RuntimeException
{
    public function __construct(public readonly string $limitKey, public readonly int $limit)
    {
        parent::__construct("Plan limit [{$limitKey}] of {$limit} reached.");
    }

    public function userMessage(): string
    {
        return (string) __('billing.limit_reached', [
            'limit' => __("billing.limits.{$this->limitKey}"),
            'count' => $this->limit,
        ]);
    }
}
