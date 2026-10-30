<?php

declare(strict_types=1);

namespace App\Domain\Collections\Enums;

use App\Domain\Shared\Enums\HasValues;

enum Tone: string
{
    use HasValues;

    case Friendly = 'friendly';
    case Neutral = 'neutral';
    case Firm = 'firm';
    case Final = 'final';

    public function rank(): int
    {
        return match ($this) {
            self::Friendly => 0,
            self::Neutral => 1,
            self::Firm => 2,
            self::Final => 3,
        };
    }

    public static function fromRank(int $rank): self
    {
        return match (max(0, min(3, $rank))) {
            0 => self::Friendly,
            1 => self::Neutral,
            2 => self::Firm,
            default => self::Final,
        };
    }

    public function isAbove(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    public function min(self $other): self
    {
        return $this->rank() <= $other->rank() ? $this : $other;
    }

    public function templateKey(): TemplateKey
    {
        return TemplateKey::from('reminder_'.$this->value);
    }
}
