<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Models\Invoice;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $invoice_id
 * @property int $client_id
 * @property int $amount_minor
 * @property string $currency
 * @property PaymentMethod $method
 * @property PaymentStatus $status
 * @property int $refunded_minor
 * @property PaymentProvider $provider
 * @property int|null $payment_account_id
 * @property string|null $provider_payment_id
 * @property string|null $provider_checkout_session_id
 * @property int|null $payment_link_id
 * @property CarbonInterface $paid_at
 * @property string|null $reference
 * @property string|null $note
 * @property int|null $recorded_by
 * @property CarbonInterface|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Invoice $invoice
 * @property-read Client $client
 * @property-read User|null $recorder
 */
class Payment extends Model
{
    use BelongsToWorkspace;

    public const array COUNTED_STATUSES = ['succeeded', 'partially_refunded', 'refunded'];

    protected $guarded = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'refunded_minor' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'refunded_minor' => 'integer',
            'provider' => PaymentProvider::class,
            'paid_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function netMinor(): int
    {
        if (! in_array($this->status->value, self::COUNTED_STATUSES, true)) {
            return 0;
        }

        return $this->amount_minor - $this->refunded_minor;
    }
}
