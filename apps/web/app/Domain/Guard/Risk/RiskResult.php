<?php

declare(strict_types=1);

namespace App\Domain\Guard\Risk;

use App\Domain\Analytics\Enums\RiskBand;

final readonly class RiskResult
{
    /** @param  list<array{code: string, points: int, params: array<string, string|int>}>  $signals */
    public function __construct(
        public int $score,
        public RiskBand $band,
        public array $signals,
    ) {}

    /** @param  list<array{code: string, points: int, params: array<string, string|int>}>  $signals */
    public static function fromSignals(array $signals): self
    {
        $score = min(100, array_sum(array_map(fn (array $signal): int => $signal['points'], $signals)));

        return new self($score, self::band($score), $signals);
    }

    public static function band(int $score): RiskBand
    {
        return match (true) {
            $score >= 50 => RiskBand::High,
            $score >= 25 => RiskBand::Medium,
            default => RiskBand::Low,
        };
    }

    public function has(string $code): bool
    {
        foreach ($this->signals as $signal) {
            if ($signal['code'] === $code) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_map(fn (array $signal): string => $signal['code'], $this->signals);
    }
}
