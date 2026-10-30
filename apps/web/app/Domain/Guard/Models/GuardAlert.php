<?php

declare(strict_types=1);

namespace App\Domain\Guard\Models;

use App\Domain\Guard\Enums\AlertSeverity;
use App\Domain\Guard\Enums\AlertStatus;
use App\Domain\Guard\Enums\AlertSubjectType;
use App\Domain\Guard\Enums\AlertType;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property AlertType $type
 * @property AlertSeverity $severity
 * @property AlertSubjectType $subject_type
 * @property int $subject_id
 * @property array<string, string|int|float|null> $data
 * @property AlertStatus $status
 * @property string $dedupe_key
 * @property int|null $acknowledged_by
 * @property CarbonInterface|null $acknowledged_at
 * @property int|null $resolved_by
 * @property CarbonInterface|null $resolved_at
 * @property string|null $resolution_note
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User|null $acknowledger
 */
class GuardAlert extends Model
{
    use BelongsToWorkspace;

    protected $guarded = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'data' => '{}',
        'status' => 'open',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'severity' => AlertSeverity::class,
            'subject_type' => AlertSubjectType::class,
            'subject_id' => 'integer',
            'data' => 'array',
            'status' => AlertStatus::class,
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', '!=', AlertStatus::Resolved->value);
    }
}
