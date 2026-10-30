<?php

declare(strict_types=1);

namespace App\Domain\Imports\Models;

use App\Domain\Imports\Enums\ImportActivationStatus;
use App\Domain\Imports\Enums\ImportKind;
use App\Domain\Imports\Enums\ImportPreset;
use App\Domain\Imports\Enums\ImportStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property ImportKind $kind
 * @property ImportPreset $preset
 * @property ImportStatus $status
 * @property string|null $file_path
 * @property string $original_name
 * @property int $size_bytes
 * @property string $delimiter
 * @property list<string> $headers
 * @property array<string, string|null>|null $mapping
 * @property array<string, mixed> $options
 * @property int $rows_total
 * @property int $rows_processed
 * @property int $rows_imported
 * @property int $rows_skipped
 * @property int $rows_failed
 * @property list<array{row: int, field: string|null, code: string}> $errors
 * @property ImportActivationStatus $activation_status
 * @property CarbonInterface|null $activated_at
 * @property int|null $activated_by
 * @property int $created_by
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User $creator
 */
class Import extends Model
{
    use BelongsToWorkspace;

    public const int MAX_ERRORS = 500;

    protected $guarded = ['id', 'workspace_id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'preset' => 'generic',
        'status' => 'uploaded',
        'delimiter' => ',',
        'headers' => '[]',
        'options' => '{}',
        'errors' => '[]',
        'rows_total' => 0,
        'rows_processed' => 0,
        'rows_imported' => 0,
        'rows_skipped' => 0,
        'rows_failed' => 0,
        'activation_status' => 'pending',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => ImportKind::class,
            'preset' => ImportPreset::class,
            'status' => ImportStatus::class,
            'size_bytes' => 'integer',
            'headers' => 'array',
            'mapping' => 'array',
            'options' => 'array',
            'rows_total' => 'integer',
            'rows_processed' => 'integer',
            'rows_imported' => 'integer',
            'rows_skipped' => 'integer',
            'rows_failed' => 'integer',
            'errors' => 'array',
            'activation_status' => ImportActivationStatus::class,
            'activated_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function progress(): int
    {
        if ($this->rows_total <= 0) {
            return in_array($this->status, [ImportStatus::Completed, ImportStatus::CompletedWithErrors], true) ? 100 : 0;
        }

        return (int) min(100, floor($this->rows_processed * 100 / $this->rows_total));
    }
}
