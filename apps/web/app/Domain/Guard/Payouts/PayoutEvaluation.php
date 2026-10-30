<?php

declare(strict_types=1);

namespace App\Domain\Guard\Payouts;

use App\Domain\Guard\Approvals\ApprovalRequirement;
use App\Domain\Guard\Models\TrustedContact;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Guard\Risk\RiskResult;

final readonly class PayoutEvaluation
{
    public function __construct(
        public VendorBankAccount $account,
        public RiskResult $risk,
        public ApprovalRequirement $requirement,
        public ?int $splitTotal,
        public int $othersApprove,
        public ?TrustedContact $trustedContact,
    ) {}
}
