<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Payments\Money;
use App\Billing\Payments\PaymentLink;
use App\Billing\Payments\PaymentStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int|null $payment_account_id
 * @property string $driver
 * @property string $reference
 * @property string $provider_id
 * @property string|null $provider_payment_id
 * @property string $url
 * @property int $amount
 * @property string $currency
 * @property PaymentStatus $status
 * @property CarbonInterface|null $paid_at
 * @property CarbonInterface|null $last_checked_at
 * @property int|null $last_event_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class PaymentLinkRecord extends Model
{
    protected $table = 'payment_links';

    protected $fillable = [
        'workspace_id', 'payment_account_id', 'driver', 'reference', 'provider_id', 'provider_payment_id', 'url', 'amount', 'currency',
        'status', 'paid_at', 'last_checked_at', 'last_event_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'paid_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'last_event_at' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<PaymentAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class, 'payment_account_id');
    }

    public function toLink(): PaymentLink
    {
        return new PaymentLink($this->driver, $this->provider_id, $this->url, Money::of($this->amount, $this->currency));
    }
}
