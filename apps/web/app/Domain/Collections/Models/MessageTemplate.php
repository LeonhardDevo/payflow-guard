<?php

declare(strict_types=1);

namespace App\Domain\Collections\Models;

use App\Domain\Collections\Enums\TemplateKey;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property TemplateKey $key
 * @property string $locale
 * @property string $subject
 * @property string $body_markdown
 * @property bool $is_customized
 * @property int|null $updated_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User|null $editor
 */
class MessageTemplate extends Model
{
    use BelongsToWorkspace;

    protected $guarded = ['id', 'workspace_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'key' => TemplateKey::class,
            'is_customized' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
