<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $event_id
 * @property string $type
 * @property int|null $workspace_id
 * @property CarbonInterface $processed_at
 */
class ProcessedStripeEvent extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'event_id';

    protected $keyType = 'string';

    protected $fillable = ['event_id', 'type', 'workspace_id', 'processed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}
