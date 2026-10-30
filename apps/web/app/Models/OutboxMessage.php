<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $topic
 * @property array<string, mixed> $payload
 * @property int|null $workspace_id
 * @property CarbonInterface $available_at
 * @property CarbonInterface|null $dispatched_at
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonInterface $created_at
 */
class OutboxMessage extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'outbox_messages';

    protected $fillable = ['topic', 'payload', 'workspace_id', 'available_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'workspace_id' => 'integer',
            'available_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeTopic(Builder $query, string $topic): void
    {
        $query->where('topic', $topic);
    }

    /** @param  Builder<self>  $query */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('dispatched_at');
    }
}
