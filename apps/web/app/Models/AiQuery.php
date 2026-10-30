<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiChannel;
use App\Models\Concerns\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int|null $user_id
 * @property AiChannel $channel
 * @property string $question
 * @property string|null $answer
 * @property bool $refused
 * @property float|null $confidence
 * @property list<array<string, mixed>> $citations
 * @property list<string> $tools_used
 * @property int|null $latency_ms
 * @property string|null $provider
 * @property string|null $model
 * @property string|null $request_id
 * @property CarbonInterface $created_at
 * @property-read User|null $user
 */
class AiQuery extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id', 'user_id', 'channel', 'question', 'answer', 'refused', 'confidence', 'citations',
        'tools_used', 'latency_ms', 'provider', 'model', 'request_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => AiChannel::class,
            'refused' => 'boolean',
            'confidence' => 'float',
            'citations' => 'array',
            'tools_used' => 'array',
            'latency_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, mixed> */
    public function toContract(): array
    {
        $user = $this->user;

        return [
            'id' => $this->id,
            'channel' => $this->channel->value,
            'user' => $user !== null ? ['id' => $user->id, 'name' => $user->name] : null,
            'question' => $this->question,
            'answer' => $this->answer,
            'refused' => $this->refused,
            'confidence' => $this->confidence,
            'citations' => array_map(
                fn (array $citation): array => [
                    'document_id' => (int) ($citation['document_id'] ?? 0),
                    'title' => (string) ($citation['title'] ?? ''),
                    'page' => isset($citation['page']) ? (int) $citation['page'] : null,
                ],
                $this->citations,
            ),
            'tools_used' => $this->tools_used,
            'latency_ms' => $this->latency_ms,
            'provider' => $this->provider,
            'model' => $this->model,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
