<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\Audit\Auditor;
use App\Billing\PlanLimitExceeded;
use App\Billing\PlanLimits;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Outbox\Outbox;
use App\Outbox\Topics;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final readonly class DocumentService
{
    public function __construct(
        private Outbox $outbox,
        private Auditor $auditor,
        private PlanLimits $limits,
    ) {}

    public static function disk(): string
    {
        return (string) config('filesystems.documents_disk', 'documents');
    }

    /**
     * @param  array{title?: string|null, department?: string|null, roles?: list<string>, tags?: list<string>, version?: string|null, effective_date?: string|null}  $metadata
     *
     * @throws PlanLimitExceeded
     */
    public function upload(Workspace $workspace, User $uploader, UploadedFile $file, string $mime, array $metadata): Document
    {
        $this->limits->ensure($workspace, 'documents');
        $this->limits->ensure($workspace, 'storage_mb', (int) ceil(((int) $file->getSize()) / 1048576));

        $disk = self::disk();
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $workspace->id.'/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
        $realPath = (string) $file->getRealPath();

        Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));

        try {
            return DB::transaction(function () use ($workspace, $uploader, $file, $mime, $metadata, $disk, $path, $realPath): Document {
                $document = new Document;
                $document->fill([
                    'workspace_id' => $workspace->id,
                    'uploaded_by' => $uploader->id,
                    'title' => self::title($metadata['title'] ?? null, $file),
                    'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                    'mime' => $mime,
                    'size_bytes' => (int) $file->getSize(),
                    'disk' => $disk,
                    'path' => $path,
                    'sha256' => (string) hash_file('sha256', $realPath),
                    'department' => $metadata['department'] ?? null,
                    'roles' => $metadata['roles'] ?? [],
                    'tags' => $metadata['tags'] ?? [],
                    'version' => $metadata['version'] ?? null,
                    'effective_date' => $metadata['effective_date'] ?? null,
                    'status' => DocumentStatus::Queued,
                ]);
                $document->save();

                $this->publishUploaded($document);

                $this->auditor->record('document.uploaded', $document, metadata: [
                    'mime' => $mime,
                    'size_bytes' => $document->size_bytes,
                ], actor: $uploader, workspace: $workspace, subjectLabel: $document->title);

                return $document;
            });
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    /** @param  array{title?: string, department?: string|null, roles?: list<string>, tags?: list<string>, version?: string|null, effective_date?: string|null}  $metadata */
    public function update(Document $document, User $actor, array $metadata): Document
    {
        return DB::transaction(function () use ($document, $actor, $metadata): Document {
            $fields = ['title', 'department', 'roles', 'tags', 'version', 'effective_date'];
            $before = self::snapshot($document, $fields);

            $document->fill(array_intersect_key($metadata, array_flip($fields)));

            if (! $document->isDirty()) {
                return $document;
            }

            $document->status = DocumentStatus::Queued;
            $document->error = null;
            $document->save();

            $this->publishUploaded($document);

            $this->auditor->record(
                'document.updated',
                $document,
                Auditor::diff($before, self::snapshot($document, $fields)),
                actor: $actor,
                subjectLabel: $document->title,
            );

            return $document;
        });
    }

    public function delete(Document $document, User $actor): void
    {
        $disk = $document->disk;
        $path = $document->path;

        DB::transaction(function () use ($document, $actor): void {
            $this->outbox->publish(Topics::DOCUMENT_DELETED, [
                'document_id' => $document->id,
                'workspace_id' => $document->workspace_id,
            ], $document->workspace_id);

            $this->auditor->record('document.deleted', $document, metadata: [
                'original_name' => $document->original_name,
            ], actor: $actor, subjectLabel: $document->title);

            $document->delete();
        });

        Storage::disk($disk)->delete($path);
    }

    private function publishUploaded(Document $document): void
    {
        $this->outbox->publish(Topics::DOCUMENT_UPLOADED, [
            'document_id' => $document->id,
            'workspace_id' => $document->workspace_id,
            'title' => $document->title,
            'mime' => $document->mime,
            'ai_external_allowed' => (bool) Workspace::query()->whereKey($document->workspace_id)->value('ai_external_allowed'),
            'metadata' => [
                'department' => $document->department,
                'roles' => $document->roles,
                'tags' => $document->tags,
                'version' => $document->version,
                'effective_date' => $document->effective_date?->toDateString(),
            ],
        ], $document->workspace_id);
    }

    private static function title(?string $title, UploadedFile $file): string
    {
        $title = trim((string) $title);

        if ($title !== '') {
            return Str::limit($title, 250, '');
        }

        return Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Document', 250, '');
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private static function snapshot(Document $document, array $fields): array
    {
        $snapshot = [];

        foreach ($fields as $field) {
            $value = $document->getAttribute($field);
            $snapshot[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }

        return $snapshot;
    }
}
