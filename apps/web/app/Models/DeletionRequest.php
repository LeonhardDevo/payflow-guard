<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property int|null $requested_by
 * @property CarbonInterface $scheduled_for
 * @property CarbonInterface|null $cancelled_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class DeletionRequest extends Model
{
    public const string USER = 'user';

    public const string WORKSPACE = 'workspace';

    protected $fillable = ['subject_type', 'subject_id', 'requested_by', 'scheduled_for'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'requested_by' => 'integer',
            'scheduled_for' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at')->whereNull('completed_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFor(Builder $query, string $type, int $id): Builder
    {
        return $query->where('subject_type', $type)->where('subject_id', $id);
    }
}
