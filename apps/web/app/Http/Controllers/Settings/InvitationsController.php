<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Billing\PlanLimitExceeded;
use App\Http\Controllers\Controller;
use App\Http\Requests\Members\InviteMemberRequest;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Support\Flash;
use App\Tenancy\CurrentWorkspace;
use App\Workspaces\InvitationService;
use App\Workspaces\WorkspaceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class InvitationsController extends Controller
{
    public function store(InviteMemberRequest $request, CurrentWorkspace $current, InvitationService $invitations): RedirectResponse|JsonResponse
    {
        $workspace = $current->get();
        abort_if($workspace === null, 404);
        /** @var User $user */
        $user = $request->user();
        $email = (string) $request->validated('email');

        try {
            $invitations->invite($workspace, $user, $email, $request->role());
        } catch (WorkspaceException $exception) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['ok' => false, 'error' => $exception->reason, 'message' => $exception->userMessage()], 403);
            }

            Flash::errorMessage($exception->userMessage());

            return back();
        } catch (PlanLimitExceeded $exception) {
            Flash::errorMessage($exception->userMessage());

            return back();
        }

        Flash::success('notifications.invitations.sent', ['email' => $email]);

        return back();
    }

    public function destroy(Request $request, CurrentWorkspace $current, WorkspaceInvitation $invitation, InvitationService $invitations): RedirectResponse
    {
        abort_unless($invitation->workspace_id === $current->id(), 404);
        $this->authorize('delete', $invitation);

        /** @var User $user */
        $user = $request->user();
        $invitations->revoke($invitation, $user);

        Flash::success('notifications.invitations.revoked', ['email' => $invitation->email]);

        return back();
    }
}
