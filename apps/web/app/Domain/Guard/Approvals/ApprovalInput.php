<?php

declare(strict_types=1);

namespace App\Domain\Guard\Approvals;

use App\Domain\Analytics\Enums\RiskBand;

final readonly class ApprovalInput
{
    /** @param  array{auto_approve_below_minor: int, dual_approval_from_minor: int, auto_approve_cumulative_minor?: int|null}|null  $threshold */
    public function __construct(
        public int $amountMinor,
        public ?array $threshold,
        public RiskBand $band,
        public bool $coolingOff,
        public bool $recentChange,
        public bool $firstPayout,
        public bool $splitSuspected,
        public int $cumulative30Minor,
        public int $othersApprove,
        public int $othersRelease,
        public bool $trustedContactActive,
        public bool $allowTelegramApprovals,
    ) {}
}
