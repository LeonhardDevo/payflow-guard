<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $reference_type
 * @property string $reference_id
 * @property string $channel
 * @property string $status
 * @property string|null $error
 * @property string|null $provider_message_id
 * @property int|null $attempt
 * @property CarbonInterface|null $next_retry_at
 * @property CarbonInterface $created_at
 */
class Delivery extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['reference_type', 'reference_id', 'channel', 'status', 'error', 'provider_message_id', 'attempt', 'next_retry_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'attempt' => 'integer',
            'next_retry_at' => 'datetime',
        ];
    }
}
