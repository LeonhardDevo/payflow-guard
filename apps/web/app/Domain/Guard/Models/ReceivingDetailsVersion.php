<?php

declare(strict_types=1);

namespace App\Domain\Guard\Models;

use App\Domain\Guard\Enums\ConfirmationMode;
use App\Domain\Guard\Enums\ReceivingDetailsStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $version
 * @property string $details
 * @property string $details_hash
 * @property ReceivingDetailsStatus $status
 * @property int $requested_by
 * @property CarbonInterface $requested_at
 * @property ConfirmationMode|null $confirmation_mode
 * @property int|null $confirmed_by
 * @property int|null $trusted_confirmation_id
 * @property CarbonInterface|null $confirmed_at
 * @property CarbonInterface|null $effective_at
 * @property int|null $rejected_by
 * @property CarbonInterface|null $rejected_at
 * @property string|null $rejection_reason
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User $requester
 * @property-read User|null $confirmer
 */
class ReceivingDetailsVersion extends Model
{
    use BelongsToWorkspace;

    protected $guarded = ['id'];

    protected $hidden = ['details'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'details' => 'encrypted',
            'status' => ReceivingDetailsStatus::class,
            'requested_at' => 'datetime',
            'confirmation_mode' => ConfirmationMode::class,
            'confirmed_at' => 'datetime',
            'effective_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
