<?php

declare(strict_types=1);

namespace App\Domain\Guard\Payouts;

use App\Domain\Guard\Enums\ApprovalChannel;
use App\Domain\Guard\Enums\StepUpMethod;

final readonly class DecisionContext
{
    public function __construct(
        public ApprovalChannel $channel,
        public ?StepUpMethod $stepUpMethod = null,
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {}

    public static function web(?StepUpMethod $method, ?string $ip, ?string $userAgent): self
    {
        return new self(ApprovalChannel::Web, $method ?? StepUpMethod::None, $ip, $userAgent !== null ? mb_substr($userAgent, 0, 255) : null);
    }

    public static function telegram(): self
    {
        return new self(ApprovalChannel::Telegram);
    }
}
