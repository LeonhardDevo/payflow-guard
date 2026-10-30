<?php

declare(strict_types=1);

namespace App\Domain\Guard\Risk;

use App\Domain\Analytics\Enums\RiskBand;

final readonly class PayoutRiskInput
{
    /**
     * @param  list<int>  $releasedAmounts  newest first, same vendor and currency
     * @param  list<int>  $recentSubmittedAmounts  other non-canceled, non-rejected payouts to the vendor in the currency submitted in the last 7 days
     */
    public function __construct(
        public int $amountMinor,
        public bool $coolingOff,
        public ?string $coolingOffUntil,
        public ?RiskBand $accountChangeBand,
        public int $accountChangeScore,
        public ?int $verifiedDaysAgo,
        public int $releasedToAccount,
        public array $releasedAmounts,
        public ?string $duplicateOf,
        public array $recentSubmittedAmounts,
        public ?int $dualApprovalFromMinor,
        public bool $urgentDue,
        public ?string $dueDate,
    ) {}
}
