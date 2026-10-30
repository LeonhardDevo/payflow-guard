<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Risk;

final readonly class RiskInput
{
    public function __construct(
        public int $closedCount,
        public int $lateCount,
        public float $workspaceLateShare,
        public float $avgDaysLate,
        public int $otherOverdueInvoices,
        public float $amountRatio,
        public int $termsDays,
        public int $daysSinceDue,
        public bool $notViewed,
        public bool $disputeHistory,
    ) {}
}
