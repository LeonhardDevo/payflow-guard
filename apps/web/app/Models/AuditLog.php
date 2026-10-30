<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int|null $workspace_id
 * @property int|null $actor_id
 * @property string|null $actor_name
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $subject_label
 * @property array<string, array{0: mixed, 1: mixed}>|null $changes
 * @property array<string, mixed>|null $metadata
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property CarbonInterface $created_at
 * @property-read User|null $actor
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id', 'actor_id', 'actor_name', 'action', 'subject_type', 'subject_id',
        'subject_label', 'changes', 'metadata', 'ip', 'user_agent', 'request_id',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Audit logs are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Audit logs are append-only.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
