<?php

declare(strict_types=1);

namespace App\Domain\Collections\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string|null $description
 * @property bool $is_default
 * @property int $max_reminders
 * @property int $min_interval_days
 * @property int $escalate_after_days
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read Collection<int, ReminderPolicyStep> $steps
 */
class ReminderPolicy extends Model
{
    use BelongsToWorkspace;
    use SoftDeletes;

    protected $guarded = ['id', 'workspace_id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_default' => false,
        'max_reminders' => 8,
        'min_interval_days' => 3,
        'escalate_after_days' => 45,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'max_reminders' => 'integer',
            'min_interval_days' => 'integer',
            'escalate_after_days' => 'integer',
        ];
    }

    /** @return HasMany<ReminderPolicyStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(ReminderPolicyStep::class, 'policy_id')->orderBy('offset_days');
    }
}
