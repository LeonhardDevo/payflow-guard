<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Flash;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class WorkspaceSwitchController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace, CurrentWorkspace $current): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->belongsToWorkspace($workspace), 403);

        $current->switchTo($user, $workspace);
        Flash::success('notifications.workspace.switched', ['name' => $workspace->name]);

        return redirect()->route('dashboard');
    }
}
