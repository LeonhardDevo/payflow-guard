<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ai\AiClient;
use App\Ai\AiServiceException;
use App\Billing\PlanLimitExceeded;
use App\Billing\PlanLimits;
use App\Enums\DocumentStatus;
use App\Enums\WorkspaceRole;
use App\Http\Requests\Knowledge\StoreDocumentRequest;
use App\Http\Requests\Knowledge\UpdateDocumentRequest;
use App\Knowledge\DocumentFileInspector;
use App\Knowledge\DocumentService;
use App\Models\Document;
use App\Models\User;
use App\Support\Flash;
use App\Support\Paginated;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class KnowledgeController extends Controller
{
    private const int DOWNLOAD_TTL_MINUTES = 15;

    public function index(Request $request, CurrentWorkspace $current, PlanLimits $limits): Response
    {
        $this->authorize('viewAny', Document::class);
        $workspace = $current->get();
        abort_if($workspace === null, 404);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(DocumentStatus::values())],
            'department' => ['nullable', 'string', 'max:120'],
        ]);

        $documents = Document::query()
            ->with('uploader:id,name')
            ->when($filters['q'] ?? null, fn ($query, string $q) => $query->where(function ($inner) use ($q): void {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $inner->where('title', 'ilike', $like)->orWhere('original_name', 'ilike', $like);
            }))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['department'] ?? null, fn ($query, string $department) => $query->where('department', $department))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('knowledge/Index', [
            'documents' => Paginated::from($documents, fn (Document $document): array => [
                ...$document->toContract(),
                'download_url' => $this->downloadUrl($document),
            ]),
            'filters' => array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== ''),
            'departments' => $this->departments(),
            'roles' => WorkspaceRole::options(),
            'limits' => [
                'used' => $limits->used($workspace, 'documents'),
                'limit' => $limits->limit($workspace, 'documents'),
            ],
            'accept' => DocumentFileInspector::acceptList(),
            'maxSizeMb' => DocumentFileInspector::MAX_SIZE_MB,
        ]);
    }

    public function store(StoreDocumentRequest $request, CurrentWorkspace $current, DocumentService $documents): RedirectResponse
    {
        $workspace = $current->get();
        abort_if($workspace === null, 404);
        /** @var User $user */
        $user = $request->user();

        try {
            $document = $documents->upload($workspace, $user, $request->uploadedFile(), $request->sniffedMime(), $request->metadata());
        } catch (PlanLimitExceeded $exception) {
            Flash::errorMessage($exception->userMessage());

            return back();
        }

        Flash::success('notifications.documents.uploaded', ['title' => $document->title]);

        return back();
    }

    public function show(Document $document, AiClient $ai): Response
    {
        $this->authorize('view', $document);
        $document->loadMissing('uploader:id,name');

        return Inertia::render('knowledge/Show', [
            'document' => [
                ...$document->toContract(),
                'download_url' => $this->downloadUrl($document),
            ],
            'chunksPreview' => $document->status === DocumentStatus::Indexed
                ? $this->chunksPreview($ai, $document)
                : [],
            'departments' => $this->departments(),
            'roles' => WorkspaceRole::options(),
        ]);
    }

    public function update(UpdateDocumentRequest $request, Document $document, DocumentService $documents): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $documents->update($document, $user, $request->metadata());

        Flash::success('notifications.documents.updated', ['title' => $document->title]);

        return back();
    }

    public function destroy(Request $request, Document $document, DocumentService $documents): RedirectResponse
    {
        $this->authorize('delete', $document);
        /** @var User $user */
        $user = $request->user();
        $title = $document->title;

        $documents->delete($document, $user);

        Flash::success('notifications.documents.deleted', ['title' => $title]);

        return redirect()->route('knowledge.index');
    }

    public function download(Document $document): StreamedResponse
    {
        $this->authorize('view', $document);

        return Storage::disk($document->disk)->download($document->path, $document->original_name, [
            'Content-Type' => $document->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function downloadUrl(Document $document): string
    {
        return URL::temporarySignedRoute(
            'knowledge.download',
            now()->addMinutes(self::DOWNLOAD_TTL_MINUTES),
            ['document' => $document->id],
        );
    }

    /** @return list<string> */
    private function departments(): array
    {
        /** @var list<string> $departments */
        $departments = Document::query()
            ->whereNotNull('department')
            ->distinct()
            ->orderBy('department')
            ->pluck('department')
            ->all();

        return $departments;
    }

    /** @return list<array{page: int|null, section: string|null, content: string}> */
    private function chunksPreview(AiClient $ai, Document $document): array
    {
        try {
            return $ai->documentChunks($document->id, $document->workspace_id);
        } catch (AiServiceException) {
            return [];
        }
    }
}
