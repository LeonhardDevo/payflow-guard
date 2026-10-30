<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $plan
 * @property SubscriptionStatus $status
 * @property string $interval
 * @property string $gateway
 * @property string|null $currency
 * @property CarbonInterface|null $payment_failed_at
 * @property string|null $stripe_customer_id
 * @property string|null $stripe_subscription_id
 * @property string|null $stripe_price_id
 * @property string|null $provider_payment_method_id
 * @property string|null $scheduled_plan
 * @property string|null $scheduled_interval
 * @property CarbonInterface|null $last_charge_attempt_at
 * @property CarbonInterface|null $current_period_end
 * @property bool $cancel_at_period_end
 * @property CarbonInterface|null $trial_ends_at
 * @property CarbonInterface|null $canceled_at
 * @property int|null $last_event_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Subscription extends Model
{
    protected $fillable = [
        'workspace_id', 'gateway', 'plan', 'status', 'interval', 'currency', 'payment_failed_at', 'stripe_customer_id', 'stripe_subscription_id',
        'stripe_price_id', 'current_period_end', 'cancel_at_period_end', 'trial_ends_at', 'canceled_at',
        'last_event_at', 'provider_payment_method_id', 'scheduled_plan', 'scheduled_interval', 'last_charge_attempt_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'gateway' => 'stripe',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'current_period_end' => 'datetime',
            'trial_ends_at' => 'datetime',
            'canceled_at' => 'datetime',
            'payment_failed_at' => 'datetime',
            'last_charge_attempt_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'last_event_at' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function grantsPlan(): bool
    {
        return $this->status->grantsPlan();
    }

    /** @return array{plan: string, status: string, interval: string, currency: string|null, gateway: string, current_period_end: string|null, cancel_at_period_end: bool, trial_ends_at: string|null, payment_failed_at: string|null, scheduled_plan: string|null, scheduled_interval: string|null} */
    public function toContract(): array
    {
        return [
            'plan' => $this->plan,
            'status' => $this->status->value,
            'interval' => $this->interval,
            'currency' => $this->currency,
            'gateway' => $this->gateway,
            'current_period_end' => $this->current_period_end?->toIso8601String(),
            'cancel_at_period_end' => $this->cancel_at_period_end,
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'payment_failed_at' => $this->payment_failed_at?->toIso8601String(),
            'scheduled_plan' => $this->scheduled_plan,
            'scheduled_interval' => $this->scheduled_interval,
        ];
    }
}
