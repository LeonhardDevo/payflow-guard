<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\DocumentStatusRequest;
use App\Models\Document;
use App\Notifications\DocumentProcessed;
use App\Tenancy\WorkspaceScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentsController extends Controller
{
    public function file(int $document): StreamedResponse|JsonResponse
    {
        $model = $this->find($document);

        if ($model === null || ! Storage::disk($model->disk)->exists($model->path)) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return Storage::disk($model->disk)->response($model->path, $model->original_name, [
            'Content-Type' => $model->mime,
            'X-Document-Id' => (string) $model->id,
            'X-Workspace-Id' => (string) $model->workspace_id,
            'X-Content-Sha256' => $model->sha256,
            'Cache-Control' => 'no-store',
        ]);
    }

    public function status(DocumentStatusRequest $request, int $document): JsonResponse
    {
        $model = $this->find($document);

        if ($model === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $status = DocumentStatus::from((string) $request->validated('status'));
        $previous = $model->status;

        $model->status = $status;
        $model->pages = $request->validated('pages') ?? $model->pages;
        $model->chunks = $request->validated('chunks') ?? $model->chunks;
        $model->ocr_used = (bool) ($request->validated('ocr_used') ?? $model->ocr_used);
        $model->error = $status === DocumentStatus::Failed ? (string) ($request->validated('error') ?? 'unknown_error') : null;
        $model->indexed_at = $status === DocumentStatus::Indexed ? now() : $model->indexed_at;
        $model->save();

        if ($previous !== $status && in_array($status, [DocumentStatus::Indexed, DocumentStatus::Failed], true)) {
            $model->loadMissing('uploader')->uploader?->notify(new DocumentProcessed($model));
        }

        return response()->json(['id' => $model->id, 'status' => $model->status->value]);
    }

    private function find(int $id): ?Document
    {
        return Document::query()->withoutGlobalScope(WorkspaceScope::class)->find($id);
    }
}
