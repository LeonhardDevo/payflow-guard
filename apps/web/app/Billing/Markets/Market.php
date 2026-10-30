<?php

declare(strict_types=1);

namespace App\Billing\Markets;

final readonly class Market
{
    public function __construct(
        public string $country,
        public string $currency,
        public string $gateway,
    ) {}

    /** @return array{country: string, currency: string, gateway: string} */
    public function toContract(): array
    {
        return [
            'country' => $this->country,
            'currency' => $this->currency,
            'gateway' => $this->gateway,
        ];
    }
}
