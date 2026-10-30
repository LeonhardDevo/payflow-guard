<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Models\Workspace;

final readonly class PaymentRequest
{
    /** @param  array<string, string>  $metadata */
    public function __construct(
        public Workspace $workspace,
        public string $reference,
        public string $description,
        public Money $money,
        public string $returnUrl,
        public ?string $customerEmail = null,
        public array $metadata = [],
    ) {}

    public function idempotencyKey(): string
    {
        return 'payment-'.$this->workspace->id.'-'.$this->reference.'-'.$this->money->amount.'-'.strtolower($this->money->currency);
    }

    /** @return array<string, string> */
    public function providerMetadata(): array
    {
        return [
            ...$this->metadata,
            'workspace_id' => (string) $this->workspace->id,
            'reference' => $this->reference,
        ];
    }
}
