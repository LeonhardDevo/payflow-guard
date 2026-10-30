<?php

declare(strict_types=1);

namespace App\Domain\Collections\Models;

use App\Domain\Collections\Enums\StepTone;
use App\Models\Concerns\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $policy_id
 * @property int $position
 * @property int $offset_days
 * @property StepTone $tone
 * @property bool $attach_pdf
 * @property bool $include_payment_link
 * @property bool $include_late_fee_notice
 * @property bool $notify_owner
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read ReminderPolicy $policy
 */
class ReminderPolicyStep extends Model
{
    use BelongsToWorkspace;

    protected $guarded = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'tone' => 'auto',
        'attach_pdf' => true,
        'include_payment_link' => true,
        'include_late_fee_notice' => false,
        'notify_owner' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'offset_days' => 'integer',
            'tone' => StepTone::class,
            'attach_pdf' => 'boolean',
            'include_payment_link' => 'boolean',
            'include_late_fee_notice' => 'boolean',
            'notify_owner' => 'boolean',
        ];
    }

    /** @return BelongsTo<ReminderPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(ReminderPolicy::class, 'policy_id')->withTrashed();
    }
}
