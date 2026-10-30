<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Domain\Notifications\Enums\DigestChannel;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property list<string> $telegram_events
 * @property list<string> $email_events
 * @property DigestChannel $digest_channel
 * @property string|null $quiet_hours_start
 * @property string|null $quiet_hours_end
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class MemberNotificationSetting extends Model
{
    use BelongsToWorkspace;

    protected $guarded = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'telegram_events' => '[]',
        'email_events' => '[]',
        'digest_channel' => 'telegram',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'telegram_events' => 'array',
            'email_events' => 'array',
            'digest_channel' => DigestChannel::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
