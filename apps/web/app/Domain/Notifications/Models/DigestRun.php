<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $workspace_id
 * @property CarbonInterface $local_date
 * @property string $kind
 * @property int $recipients
 * @property CarbonInterface|null $sent_at
 */
class DigestRun extends Model
{
    use BelongsToWorkspace;

    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'local_date' => 'date',
            'recipients' => 'integer',
            'sent_at' => 'datetime',
        ];
    }
}
