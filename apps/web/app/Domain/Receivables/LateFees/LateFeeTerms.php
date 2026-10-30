<?php

declare(strict_types=1);

namespace App\Domain\Receivables\LateFees;

use App\Domain\Receivables\Enums\LateFeeType;
use App\Domain\Shared\Money\MoneyData;

final readonly class LateFeeTerms
{
    public function __construct(
        public LateFeeType $type,
        public ?int $rateBp,
        public ?int $fixedMinor,
        public int $graceDays,
        public ?int $capBp,
    ) {}

    public static function none(): self
    {
        return new self(LateFeeType::None, null, null, 0, null);
    }

    public function isActive(): bool
    {
        return $this->type !== LateFeeType::None;
    }

    /** @return array{type: string, rate_bp: int|null, fixed: array{minor: int, currency: string}|null, grace_days: int, cap_bp: int|null} */
    public function toContract(string $currency): array
    {
        return [
            'type' => $this->type->value,
            'rate_bp' => $this->rateBp,
            'fixed' => $this->fixedMinor !== null ? MoneyData::of($this->fixedMinor, $currency) : null,
            'grace_days' => $this->graceDays,
            'cap_bp' => $this->capBp,
        ];
    }
}
