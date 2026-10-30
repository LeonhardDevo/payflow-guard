<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\DeleteWorkspaceRequest;
use App\Http\Requests\Workspaces\UpdateWorkspaceRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Privacy\DeletionRequests;
use App\Support\Flash;
use App\Support\Options;
use App\Tenancy\CurrentWorkspace;
use App\Workspaces\WorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Response;

final class WorkspaceSettingsController extends Controller
{
    public function edit(Request $request, CurrentWorkspace $current): Response
    {
        $workspace = $this->workspace($current);
        $this->authorize('view', $workspace);

        return Inertia::render('settings/Workspace', [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'timezone' => $workspace->timezone,
                'currency' => $workspace->currency,
                'locale' => $workspace->locale,
            ],
            'timezones' => Options::timezones(),
            'currencies' => Options::currencies(),
        ]);
    }

    public function update(UpdateWorkspaceRequest $request, CurrentWorkspace $current, WorkspaceService $workspaces): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{name?: string, timezone?: string, currency?: string, locale?: string} $data */
        $data = array_filter($request->validated(), fn (mixed $value): bool => $value !== null);
        $workspaces->update($this->workspace($current), $user, $data);

        Flash::success('notifications.workspace.updated');

        return back();
    }

    public function destroy(DeleteWorkspaceRequest $request, CurrentWorkspace $current, DeletionRequests $deletions): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $this->workspace($current);
        $deletion = $deletions->scheduleWorkspace($workspace, $user);

        Flash::success('notifications.privacy.workspace_deletion_scheduled', [
            'name' => $workspace->name,
            'date' => $deletion->scheduled_for->settings(['locale' => App::getLocale()])->isoFormat('LL'),
        ]);

        return redirect()->route('settings.privacy');
    }

    private function workspace(CurrentWorkspace $current): Workspace
    {
        $workspace = $current->get();
        abort_if($workspace === null, 404);

        return $workspace;
    }
}
