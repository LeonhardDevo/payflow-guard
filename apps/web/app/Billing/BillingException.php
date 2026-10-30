<?php

declare(strict_types=1);

namespace App\Billing;

use RuntimeException;
use Throwable;

final class BillingException extends RuntimeException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct("Billing error: {$reason}", 0, $previous);
    }

    public function userMessage(): string
    {
        return (string) __("billing.errors.{$this->reason}");
    }
}
