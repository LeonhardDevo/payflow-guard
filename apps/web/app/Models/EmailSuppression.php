<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $email
 * @property int|null $workspace_id
 * @property string $reason
 * @property string $source
 * @property CarbonInterface $created_at
 */
class EmailSuppression extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['email', 'workspace_id', 'reason', 'source'];
}
