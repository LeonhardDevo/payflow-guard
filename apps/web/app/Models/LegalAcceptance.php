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
 * @property string $document
 * @property string $version
 * @property string $locale
 * @property CarbonInterface $accepted_at
 * @property string|null $ip
 * @property string|null $user_agent
 */
class LegalAcceptance extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'workspace_id', 'document', 'version', 'locale', 'accepted_at', 'ip', 'user_agent'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
