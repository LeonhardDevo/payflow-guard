<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use Carbon\CarbonInterface;

final readonly class PaymentLink
{
    public function __construct(
        public string $driver,
        public string $providerId,
        public string $url,
        public Money $money,
        public ?CarbonInterface $expiresAt = null,
    ) {}
}
