<?php

declare(strict_types=1);

namespace App\Domain\Receivables\LateFees;

use App\Domain\Shared\Money\MoneyData;

final readonly class LateFeeSuggestion
{
    public function __construct(
        public int $amountMinor,
        public int $chargeableDays,
        public LateFeeTerms $terms,
        public string $source,
        public int $alreadyAppliedMinor,
        public string $currency,
    ) {}

    /** @return array<string, mixed> */
    public function toContract(): array
    {
        return [
            'amount' => MoneyData::of($this->amountMinor, $this->currency),
            'chargeable_days' => $this->chargeableDays,
            'terms' => $this->terms->toContract($this->currency),
            'source' => $this->source,
            'already_applied' => MoneyData::of($this->alreadyAppliedMinor, $this->currency),
        ];
    }
}
