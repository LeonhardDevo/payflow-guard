<?php

declare(strict_types=1);

namespace App\Billing\Fake;

use App\Enums\BillingInterval;

final readonly class FakeCheckoutSession
{
    public function __construct(
        public string $id,
        public int $workspaceId,
        public int $userId,
        public string $plan,
        public BillingInterval $interval,
        public int $amount,
        public string $currency,
        public string $customerId,
        public string $successUrl,
        public string $cancelUrl,
        public int $createdAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->userId,
            'plan' => $this->plan,
            'interval' => $this->interval->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'customer_id' => $this->customerId,
            'success_url' => $this->successUrl,
            'cancel_url' => $this->cancelUrl,
            'created_at' => $this->createdAt,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            workspaceId: (int) $data['workspace_id'],
            userId: (int) $data['user_id'],
            plan: (string) $data['plan'],
            interval: BillingInterval::from((string) $data['interval']),
            amount: (int) $data['amount'],
            currency: (string) $data['currency'],
            customerId: (string) $data['customer_id'],
            successUrl: (string) $data['success_url'],
            cancelUrl: (string) $data['cancel_url'],
            createdAt: (int) $data['created_at'],
        );
    }
}
