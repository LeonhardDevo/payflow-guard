<?php

declare(strict_types=1);

namespace App\Domain\Guard\Models;

use App\Domain\Guard\Enums\TrustedConfirmationStatus;
use App\Domain\Guard\Enums\TrustedSubjectType;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $contact_id
 * @property TrustedSubjectType $subject_type
 * @property int $subject_id
 * @property string $token_hash
 * @property array<string, string|int|float|null>|null $snapshot
 * @property TrustedConfirmationStatus $status
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $decided_at
 * @property string|null $comment
 * @property string|null $ip
 * @property string|null $user_agent
 * @property int $resend_count
 * @property CarbonInterface|null $reminded_at
 * @property int $created_by
 * @property CarbonInterface|null $created_at
 * @property-read TrustedContact $contact
 * @property-read User $creator
 */
class TrustedConfirmation extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'resend_count' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subject_type' => TrustedSubjectType::class,
            'subject_id' => 'integer',
            'snapshot' => 'array',
            'status' => TrustedConfirmationStatus::class,
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
            'resend_count' => 'integer',
            'reminded_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TrustedContact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(TrustedContact::class, 'contact_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
