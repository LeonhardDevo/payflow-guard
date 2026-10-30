<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $workspace_id
 * @property string $scope
 * @property string $status
 * @property string|null $disk
 * @property string|null $path
 * @property int|null $size_bytes
 * @property string|null $error
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $downloaded_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User $user
 * @property-read Workspace|null $workspace
 */
class DataExport extends Model
{
    public const string SCOPE_USER = 'user';

    public const string SCOPE_WORKSPACE = 'workspace';

    public const string PENDING = 'pending';

    public const string PROCESSING = 'processing';

    public const string READY = 'ready';

    public const string FAILED = 'failed';

    public const string EXPIRED = 'expired';

    protected $fillable = ['user_id', 'workspace_id', 'scope', 'status'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'downloaded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::READY
            && $this->path !== null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
