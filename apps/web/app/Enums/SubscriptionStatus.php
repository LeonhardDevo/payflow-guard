<?php

declare(strict_types=1);

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Incomplete = 'incomplete';

    public static function fromStripe(string $status): self
    {
        return match ($status) {
            'trialing' => self::Trialing,
            'active' => self::Active,
            'past_due', 'unpaid' => self::PastDue,
            'canceled', 'incomplete_expired' => self::Canceled,
            default => self::Incomplete,
        };
    }

    public function grantsPlan(): bool
    {
        return in_array($this->value, config('billing.active_statuses', []), true);
    }
}
