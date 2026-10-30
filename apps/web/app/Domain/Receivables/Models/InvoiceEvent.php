<?php

declare(strict_types=1);

namespace App\Domain\Receivables\Models;

use App\Domain\Receivables\Enums\EventActorType;
use App\Domain\Receivables\Enums\InvoiceEventType;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $invoice_id
 * @property int $client_id
 * @property InvoiceEventType $type
 * @property EventActorType $actor_type
 * @property int|null $actor_id
 * @property array<string, string|int|bool|null> $data
 * @property CarbonInterface $occurred_at
 * @property-read User|null $actor
 * @property-read Invoice $invoice
 */
class InvoiceEvent extends Model
{
    use BelongsToWorkspace;

    public $timestamps = false;

    protected $guarded = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'data' => '{}',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Invoice events are append-only.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => InvoiceEventType::class,
            'actor_type' => EventActorType::class,
            'actor_id' => 'integer',
            'data' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
    }
}
