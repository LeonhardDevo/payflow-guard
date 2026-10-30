<?php

declare(strict_types=1);

namespace App\Domain\Guard\Models;

use App\Domain\Guard\Enums\TrustedContactStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string $email
 * @property string $locale
 * @property TrustedContactStatus $status
 * @property CarbonInterface|null $verified_at
 * @property CarbonInterface|null $active_from
 * @property int $created_by
 * @property CarbonInterface|null $revoked_at
 * @property int|null $revoked_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User $creator
 */
class TrustedContact extends Model
{
    use BelongsToWorkspace;

    protected $guarded = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'locale' => 'en',
        'status' => 'pending_verification',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TrustedContactStatus::class,
            'verified_at' => 'datetime',
            'active_from' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActiveAt(CarbonInterface $now): bool
    {
        return $this->status === TrustedContactStatus::Active && $this->active_from !== null && $this->active_from->lte($now);
    }
}
