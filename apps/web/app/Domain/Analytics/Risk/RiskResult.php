<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Risk;

use App\Domain\Analytics\Enums\RiskBand;

final readonly class RiskResult
{
    /** @param  list<array{code: string, weight: float, value: float, contribution: float, params: array<string, string|int|float>}>  $factors */
    public function __construct(
        public float $z,
        public float $probability,
        public int $score,
        public RiskBand $band,
        public array $factors,
    ) {}
}
