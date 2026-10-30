<?php

declare(strict_types=1);

namespace App\Domain\Guard\Eligibility;

use App\Enums\WorkspaceRole;
use App\Models\User;
use Carbon\CarbonImmutable;

final readonly class EligibleMember
{
    public function __construct(
        public User $user,
        public WorkspaceRole $role,
        public ?CarbonImmutable $eligibleFrom,
        public bool $missingSecondFactor,
        public bool $trustDelayPassed,
    ) {}

    public function eligible(): bool
    {
        return $this->trustDelayPassed && ! $this->missingSecondFactor;
    }

    public function reason(): ?string
    {
        return match (true) {
            ! $this->trustDelayPassed => 'not_eligible_yet',
            $this->missingSecondFactor => 'second_factor_missing',
            default => null,
        };
    }
}
