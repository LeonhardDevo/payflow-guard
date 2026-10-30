<?php

declare(strict_types=1);

namespace App\Workspaces;

use App\Audit\Auditor;
use App\Enums\WorkspaceRole;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\Contracts\AccountDeletionHook;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class AccountDeletion
{
    public function __construct(
        private WorkspaceService $workspaces,
        private Auditor $auditor,
    ) {}

    /** @return Collection<int, Workspace> */
    public function blockingWorkspaces(User $user): Collection
    {
        return $this->soleOwnerWorkspaces($user)
            ->filter(fn (Workspace $workspace): bool => $workspace->members()->count() > 1)
            ->values();
    }

    /** @throws WorkspaceException */
    public function delete(User $user): void
    {
        $blocking = $this->blockingWorkspaces($user);

        if ($blocking->isNotEmpty()) {
            throw new WorkspaceException('transfer_ownership_first', ['workspace' => $blocking->first()->name]);
        }

        DB::transaction(function () use ($user): void {
            foreach ($this->soleOwnerWorkspaces($user) as $workspace) {
                $this->workspaces->delete($workspace, $user);
            }

            $this->auditor->record('auth.account_deleted', $user, actor: $user, workspace: null, subjectLabel: $user->email, platform: true);

            if ($this->mustPseudonymise($user)) {
                $this->pseudonymise($user);

                return;
            }

            $user->delete();
        });
    }

    public function mustPseudonymise(User $user): bool
    {
        foreach ((array) config('product.privacy.account_deletion_hooks', []) as $class) {
            $hook = is_string($class) ? app($class) : null;

            if ($hook instanceof AccountDeletionHook && $hook->mustPseudonymise($user)) {
                return true;
            }
        }

        return false;
    }

    private function pseudonymise(User $user): void
    {
        DB::table('workspace_user')->where('user_id', $user->id)->delete();
        DB::table('passkeys')->where('user_id', $user->id)->delete();
        TelegramAccount::query()->where('user_id', $user->id)->delete();

        $user->forceFill([
            'name' => 'Deleted user #'.$user->id,
            'email' => 'deleted-'.$user->id.'@deleted.invalid',
            'password' => Str::random(64),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'remember_token' => null,
            'current_workspace_id' => null,
            'pseudonymised_at' => now(),
        ])->save();
    }

    /** @return Collection<int, Workspace> */
    private function soleOwnerWorkspaces(User $user): Collection
    {
        return $user->workspaces()
            ->wherePivot('role', WorkspaceRole::Owner->value)
            ->get()
            ->filter(fn (Workspace $workspace): bool => $workspace->ownersCount() === 1)
            ->values();
    }
}
