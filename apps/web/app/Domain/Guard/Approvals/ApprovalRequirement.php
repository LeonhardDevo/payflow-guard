<?php

declare(strict_types=1);

namespace App\Domain\Guard\Approvals;

use App\Domain\Analytics\Enums\RiskBand;

final readonly class ApprovalRequirement
{
    /** @param  array{threshold: int, cumulative_used: int, cumulative_cap: int}|null  $autoApproveBasis */
    public function __construct(
        public int $required,
        public bool $releaseRequiresOtherUser,
        public bool $autoApproved,
        public ?array $autoApproveBasis,
        public int $available,
        public bool $blocked,
        public bool $telegramAllowed,
        public bool $usesTrustedContact,
    ) {}

    public static function for(ApprovalInput $input): self
    {
        $threshold = $input->threshold;
        $autoOk = false;
        $basis = null;

        if ($threshold !== null) {
            $cap = $threshold['auto_approve_cumulative_minor'] ?? (2 * $threshold['auto_approve_below_minor']);
            $autoOk = $input->amountMinor < $threshold['auto_approve_below_minor']
                && $input->band === RiskBand::Low
                && ! $input->coolingOff
                && ! $input->firstPayout
                && ! $input->recentChange
                && ! $input->splitSuspected
                && $input->cumulative30Minor + $input->amountMinor <= $cap;
            $basis = [
                'threshold' => $threshold['auto_approve_below_minor'],
                'cumulative_used' => $input->cumulative30Minor,
                'cumulative_cap' => $cap,
            ];
        }

        $releaseOther = false;

        if ($autoOk && $input->othersRelease >= 1) {
            $required = 0;
            $releaseOther = true;
        } elseif ($autoOk) {
            $required = 1;
        } elseif ($input->band === RiskBand::High || $input->coolingOff || $input->splitSuspected
            || ($threshold !== null && $input->amountMinor >= $threshold['dual_approval_from_minor'])) {
            $required = 2;
        } else {
            $required = 1;
        }

        $available = $input->othersApprove + ($input->trustedContactActive ? 1 : 0);
        $blocked = $available < $required;

        return new self(
            required: $required,
            releaseRequiresOtherUser: $releaseOther,
            autoApproved: $required === 0,
            autoApproveBasis: $required === 0 ? $basis : null,
            available: $available,
            blocked: $blocked,
            telegramAllowed: $input->allowTelegramApprovals && $input->band !== RiskBand::High && ! $input->coolingOff && ! $input->splitSuspected,
            usesTrustedContact: ! $blocked && $required > 0 && $input->othersApprove < $required && $input->trustedContactActive,
        );
    }
}
