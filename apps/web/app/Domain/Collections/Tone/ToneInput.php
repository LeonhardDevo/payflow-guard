<?php

declare(strict_types=1);

namespace App\Domain\Collections\Tone;

use App\Domain\Collections\Enums\StepTone;
use App\Domain\Receivables\Enums\LatePayerClass;
use App\Domain\Receivables\Enums\ToneCeiling;

final readonly class ToneInput
{
    public function __construct(
        public int $daysSinceDue,
        public LatePayerClass $clientClass,
        public float $amountRatio,
        public int $remindersSent,
        public int $otherOverdueInvoices,
        public ToneCeiling $ceiling = ToneCeiling::None,
        public StepTone $stepTone = StepTone::Auto,
        public float $avgDaysLate = 0.0,
        public ?float $onTimeRate = null,
    ) {}
}
