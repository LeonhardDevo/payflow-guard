<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int|null $uploaded_by
 * @property string $title
 * @property string $original_name
 * @property string $mime
 * @property int $size_bytes
 * @property string $disk
 * @property string $path
 * @property string $sha256
 * @property string|null $department
 * @property list<string> $roles
 * @property list<string> $tags
 * @property string|null $version
 * @property CarbonInterface|null $effective_date
 * @property DocumentStatus $status
 * @property int|null $pages
 * @property int|null $chunks
 * @property bool $ocr_used
 * @property string|null $error
 * @property CarbonInterface|null $indexed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User|null $uploader
 */
class Document extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id', 'uploaded_by', 'title', 'original_name', 'mime', 'size_bytes', 'disk', 'path', 'sha256',
        'department', 'roles', 'tags', 'version', 'effective_date', 'status', 'pages', 'chunks', 'ocr_used',
        'error', 'indexed_at',
    ];

    protected $hidden = ['disk', 'path'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'roles' => '[]',
        'tags' => '[]',
        'status' => 'queued',
        'ocr_used' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'tags' => 'array',
            'effective_date' => 'date',
            'status' => DocumentStatus::class,
            'size_bytes' => 'integer',
            'pages' => 'integer',
            'chunks' => 'integer',
            'ocr_used' => 'boolean',
            'indexed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return array<string, mixed> */
    public function toContract(): array
    {
        $uploader = $this->uploader;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'original_name' => $this->original_name,
            'mime' => $this->mime,
            'size_bytes' => $this->size_bytes,
            'department' => $this->department,
            'roles' => $this->roles,
            'tags' => $this->tags,
            'version' => $this->version,
            'effective_date' => $this->effective_date?->toDateString(),
            'status' => $this->status->value,
            'pages' => $this->pages,
            'chunks' => $this->chunks,
            'ocr_used' => $this->ocr_used,
            'error' => $this->error,
            'uploaded_by' => $uploader !== null ? ['id' => $uploader->id, 'name' => $uploader->name] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
