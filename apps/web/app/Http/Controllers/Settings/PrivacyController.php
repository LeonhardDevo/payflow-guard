<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Audit\Auditor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Privacy\UpdateWorkspacePrivacyRequest;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Workspaces\DeleteWorkspaceRequest;
use App\Legal\LegalAcceptances;
use App\Models\DataExport;
use App\Models\DeletionRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Outbox\Outbox;
use App\Outbox\Topics;
use App\Privacy\DataExports;
use App\Privacy\DeletionRequests;
use App\Privacy\PrivacyException;
use App\Privacy\RetentionPolicy;
use App\Support\Flash;
use App\Tenancy\CurrentWorkspace;
use App\Workspaces\AccountDeletion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PrivacyController extends Controller
{
    public function __construct(
        private readonly DataExports $exports,
        private readonly DeletionRequests $deletions,
        private readonly RetentionPolicy $retention,
    ) {}

    public function show(Request $request, CurrentWorkspace $current, LegalAcceptances $acceptances): Response
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $current->get();

        $exports = DataExport::query()
            ->where('user_id', $user->id)
            ->where(fn ($query) => $query->where('scope', DataExport::SCOPE_USER)
                ->when($workspace !== null, fn ($inner) => $inner->orWhere('workspace_id', $workspace?->id)))
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (DataExport $export): array => [
                'id' => $export->id,
                'scope' => $export->scope,
                'status' => $export->status === DataExport::READY && ! $export->isDownloadable() ? DataExport::EXPIRED : $export->status,
                'created_at' => $export->created_at?->toIso8601String(),
                'expires_at' => $export->expires_at?->toIso8601String(),
                'download_url' => $this->exports->downloadUrl($export),
            ])
            ->values()
            ->all();

        $userDeletion = $this->deletions->pendingFor(DeletionRequest::USER, $user->id);
        $workspaceDeletion = $workspace !== null ? $this->deletions->pendingFor(DeletionRequest::WORKSPACE, $workspace->id) : null;
        $blocking = app(AccountDeletion::class)->blockingWorkspaces($user)->first();

        return Inertia::render('settings/Privacy', [
            'exports' => $exports,
            'deletion' => [
                'scheduled_for' => $userDeletion?->scheduled_for->toIso8601String(),
                'grace_days' => (int) config('retention.account_deletion_grace', 14),
                'blocked_by_workspace' => $blocking?->name,
            ],
            'acceptances' => array_map(
                fn (array $row): array => ['document' => $row['document'], 'version' => $row['version'], 'accepted_at' => $row['accepted_at']],
                $acceptances->history($user),
            ),
            'privacy_workspace' => $workspace !== null ? [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'ai_external_allowed' => $workspace->ai_external_allowed,
                'retention' => $this->retention->forWorkspace($workspace),
                'retention_bounds' => array_map(
                    fn (string $key): ?array => $this->retention->bounds($key, $workspace),
                    array_combine(array_keys($this->retention->overridable()), array_keys($this->retention->overridable())),
                ),
                'deletion' => [
                    'scheduled_for' => $workspaceDeletion?->scheduled_for->toIso8601String(),
                    'grace_days' => (int) config('retention.workspace_deletion_grace', 30),
                ],
            ] : null,
            'privacy_can' => [
                'manage_workspace_privacy' => $workspace !== null && $user->can('update', $workspace),
                'export_workspace' => $workspace !== null && $user->can('delete', $workspace),
                'delete_workspace' => $workspace !== null && $user->can('delete', $workspace),
            ],
            'export_link_hours' => (int) config('retention.export_link_hours', 24),
        ]);
    }

    public function export(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(function () use ($user): void {
            $this->exports->request($user, DataExport::SCOPE_USER);
            Flash::success('notifications.privacy.export_requested');
        });
    }

    public function download(Request $request, DataExport $export): StreamedResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($export->user_id === $user->id, 404);

        if ($export->scope === DataExport::SCOPE_WORKSPACE) {
            $workspace = $export->workspace;
            abort_unless($workspace !== null && $user->can('delete', $workspace), 403);
        }

        if (! $export->isDownloadable() || $export->disk === null || $export->path === null) {
            Flash::error('notifications.privacy.export_expired');

            return redirect()->route('settings.privacy');
        }

        $this->exports->markDownloaded($export);
        $name = sprintf('%s-%s-export-%s.zip', config('product.slug'), $export->scope, $export->created_at?->format('Y-m-d') ?? 'data');

        return Storage::disk($export->disk)->download($export->path, $name, ['Cache-Control' => 'no-store, private']);
    }

    public function scheduleDeletion(ProfileDeleteRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(function () use ($user): void {
            $deletion = $this->deletions->scheduleUser($user);
            Flash::success('notifications.privacy.deletion_scheduled', ['date' => $this->date($deletion)]);
        });
    }

    public function cancelDeletion(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(function () use ($user): void {
            $this->deletions->cancelUser($user);
            Flash::success('notifications.privacy.deletion_cancelled');
        });
    }

    public function updateWorkspace(UpdateWorkspacePrivacyRequest $request, CurrentWorkspace $current, Auditor $auditor, Outbox $outbox): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $this->workspace($current);
        $before = ['ai_external_allowed' => $workspace->ai_external_allowed, 'retention' => $workspace->retention];

        if ($request->has('ai_external_allowed')) {
            $workspace->ai_external_allowed = $request->boolean('ai_external_allowed');
        }

        if ($request->has('retention')) {
            $retention = $workspace->retention;

            foreach ((array) $request->validated('retention', []) as $key => $days) {
                if ($days === null) {
                    unset($retention[$key]);
                } else {
                    $retention[$key] = (int) $days;
                }
            }

            $workspace->retention = $retention;
        }

        if ($workspace->isDirty()) {
            $consentChanged = $workspace->isDirty('ai_external_allowed');
            $workspace->save();

            if ($consentChanged) {
                $outbox->publish(Topics::WORKSPACE_REEMBED, [
                    'workspace_id' => $workspace->id,
                    'ai_external_allowed' => $workspace->ai_external_allowed,
                ], $workspace->id);
            }

            $auditor->record(
                'privacy.settings_updated',
                $workspace,
                Auditor::diff($before, ['ai_external_allowed' => $workspace->ai_external_allowed, 'retention' => $workspace->retention]),
                actor: $user,
                subjectLabel: $workspace->name,
            );
        }

        Flash::success('notifications.privacy.settings_updated');

        return back();
    }

    public function exportWorkspace(Request $request, CurrentWorkspace $current): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $this->workspace($current);
        $this->authorize('delete', $workspace);

        return $this->attempt(function () use ($user, $workspace): void {
            $this->exports->request($user, DataExport::SCOPE_WORKSPACE, $workspace);
            Flash::success('notifications.privacy.workspace_export_requested');
        });
    }

    public function scheduleWorkspaceDeletion(DeleteWorkspaceRequest $request, CurrentWorkspace $current): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $this->workspace($current);
        $deletion = $this->deletions->scheduleWorkspace($workspace, $user);

        Flash::success('notifications.privacy.workspace_deletion_scheduled', ['name' => $workspace->name, 'date' => $this->date($deletion)]);

        return back();
    }

    public function cancelWorkspaceDeletion(Request $request, CurrentWorkspace $current): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $this->workspace($current);
        $this->authorize('delete', $workspace);

        return $this->attempt(function () use ($user, $workspace): void {
            $this->deletions->cancelWorkspace($workspace, $user);
            Flash::success('notifications.privacy.workspace_deletion_cancelled');
        });
    }

    private function attempt(\Closure $action): RedirectResponse
    {
        try {
            $action();
        } catch (PrivacyException $exception) {
            Flash::errorMessage($exception->userMessage());
        }

        return back();
    }

    private function workspace(CurrentWorkspace $current): Workspace
    {
        $workspace = $current->get();
        abort_if($workspace === null, 404);

        return $workspace;
    }

    private function date(DeletionRequest $deletion): string
    {
        return $deletion->scheduled_for->settings(['locale' => App::getLocale()])->isoFormat('LL');
    }
}
