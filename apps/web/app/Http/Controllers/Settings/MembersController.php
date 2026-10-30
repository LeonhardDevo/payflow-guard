<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Members\UpdateMemberRoleRequest;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Support\Flash;
use App\Tenancy\CurrentWorkspace;
use App\Workspaces\MemberService;
use App\Workspaces\WorkspaceException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class MembersController extends Controller
{
    public function index(Request $request, CurrentWorkspace $current): Response
    {
        $workspace = $this->workspace($current);
        /** @var User $user */
        $user = $request->user();

        $members = $workspace->members()->orderBy('users.name')->get();
        $linked = TelegramAccount::query()->whereIn('user_id', $members->pluck('id'))->pluck('user_id')->all();

        $invitations = WorkspaceInvitation::query()
            ->where('workspace_id', $workspace->id)
            ->pending()
            ->with('inviter:id,name')
            ->latest()
            ->get();

        $actorRole = $current->role();

        return Inertia::render('settings/Members', [
            'members' => $members->map(fn (User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->membership?->role->value,
                'joined_at' => $member->membership?->created_at?->toIso8601String(),
                'telegram_linked' => in_array($member->id, $linked, true),
                'is_you' => $member->id === $user->id,
            ])->values()->all(),
            'invitations' => $invitations->map(fn (WorkspaceInvitation $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'invited_by' => $invitation->inviter->name ?? '',
            ])->values()->all(),
            'roles' => WorkspaceRole::options(),
            'assignableRoles' => array_map(
                fn (WorkspaceRole $role): string => $role->value,
                $actorRole?->assignableRoles() ?? [],
            ),
        ]);
    }

    public function update(UpdateMemberRoleRequest $request, CurrentWorkspace $current, User $user, MemberService $members): RedirectResponse
    {
        $workspace = $this->workspace($current);
        /** @var User $actor */
        $actor = $request->user();

        try {
            $members->changeRole($workspace, $actor, $user, $request->role());
        } catch (WorkspaceException $exception) {
            Flash::errorMessage($exception->userMessage());

            return back();
        }

        Flash::success('notifications.members.role_changed', [
            'name' => $user->name,
            'role' => $request->role()->label(),
        ]);

        return back();
    }

    public function destroy(Request $request, CurrentWorkspace $current, User $user, MemberService $members): RedirectResponse
    {
        $workspace = $this->workspace($current);
        /** @var User $actor */
        $actor = $request->user();
        $leaving = $actor->is($user);

        try {
            $members->remove($workspace, $actor, $user);
        } catch (WorkspaceException $exception) {
            Flash::errorMessage($exception->userMessage());

            return back();
        }

        if ($leaving) {
            $request->session()->forget(CurrentWorkspace::SESSION_KEY);
            $current->forget();
            Flash::success('notifications.members.left', ['workspace' => $workspace->name]);

            return redirect()->route('dashboard');
        }

        Flash::success('notifications.members.removed', ['name' => $user->name]);

        return back();
    }

    private function workspace(CurrentWorkspace $current): Workspace
    {
        $workspace = $current->get();
        abort_if($workspace === null, 404);

        return $workspace;
    }
}
