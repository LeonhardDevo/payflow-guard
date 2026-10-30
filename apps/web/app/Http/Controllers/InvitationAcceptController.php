<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Support\Flash;
use App\Workspaces\InvitationService;
use App\Workspaces\WorkspaceException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class InvitationAcceptController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $invitation = $this->find($token);
        $user = $request->user();
        $user = $user instanceof User ? $user : null;

        return Inertia::render('invitations/Accept', [
            'invitation' => [
                'workspace' => ['id' => $invitation->workspace->id, 'name' => $invitation->workspace->name],
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'inviter' => $invitation->inviter->name ?? null,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
            'status' => match (true) {
                $invitation->accepted_at !== null => 'accepted',
                $invitation->isExpired() => 'expired',
                default => 'pending',
            },
            'userExists' => User::query()->whereRaw('lower(email) = ?', [Str::lower($invitation->email)])->exists(),
            'emailMatches' => $user !== null && Str::lower($user->email) === Str::lower($invitation->email),
        ]);
    }

    public function accept(Request $request, string $token, InvitationService $invitations): RedirectResponse
    {
        $invitation = $this->find($token);
        $user = $request->user();

        if (! $user instanceof User) {
            $request->session()->put('url.intended', route('invitations.show', ['token' => $token]));
            $exists = User::query()->whereRaw('lower(email) = ?', [Str::lower($invitation->email)])->exists();

            return redirect()->route($exists ? 'login' : 'register');
        }

        try {
            $workspace = $invitations->accept($invitation, $user);
        } catch (WorkspaceException $exception) {
            Flash::errorMessage($exception->userMessage());

            return back();
        }

        Flash::success('notifications.invitations.accepted', ['workspace' => $workspace->name]);

        return redirect()->route('dashboard');
    }

    private function find(string $token): WorkspaceInvitation
    {
        abort_if(strlen($token) !== 64, 404);

        $invitation = WorkspaceInvitation::findByToken($token);
        abort_if($invitation === null, 404);

        $invitation->loadMissing(['workspace', 'inviter:id,name']);

        return $invitation;
    }
}
