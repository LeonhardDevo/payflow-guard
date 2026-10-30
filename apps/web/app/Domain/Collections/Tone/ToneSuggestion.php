<?php

declare(strict_types=1);

namespace App\Domain\Collections\Tone;

use App\Domain\Collections\Enums\Tone;

final readonly class ToneSuggestion
{
    /** @param  list<array{code: string, params: array<string, string|int|float>}>  $reasons */
    public function __construct(
        public Tone $tone,
        public array $reasons,
    ) {}

    public function withCap(Tone $cap, string $reasonCode): self
    {
        if (! $this->tone->isAbove($cap)) {
            return $this;
        }

        return new self($cap, [...$this->reasons, ['code' => $reasonCode, 'params' => []]]);
    }

    /** @return array{tone: string, reasons: list<array{code: string, params: array<string, string|int|float>}>} */
    public function toContract(): array
    {
        return ['tone' => $this->tone->value, 'reasons' => $this->reasons];
    }
}
