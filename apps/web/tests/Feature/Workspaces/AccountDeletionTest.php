<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Privacy\DeletionRequests;

it('refuses to delete the last owner of a workspace that still has members', function (): void {
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $this->member(WorkspaceRole::Member, $workspace);

    $this->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHas('error', __('notifications.privacy.transfer_ownership_first', ['workspace' => $workspace->name]));

    expect($owner->fresh())->not->toBeNull()
        ->and($workspace->fresh())->not->toBeNull();
});

it('deletes the account together with workspaces where the user is alone', function (): void {
    [$owner, $solo] = $this->signInMember(WorkspaceRole::Owner);
    [, $shared] = $this->member(WorkspaceRole::Owner);
    $shared->addMember($owner, WorkspaceRole::Owner);

    $this->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect(route('settings.privacy'));
    expect(User::query()->find($owner->id))->not->toBeNull();

    $this->travel(15)->days();
    app(DeletionRequests::class)->runDue();

    expect(User::query()->find($owner->id))->toBeNull()
        ->and(Workspace::query()->find($solo->id))->toBeNull()
        ->and(Workspace::query()->find($shared->id))->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'auth.account_deleted')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'workspace.deleted')->where('workspace_id', $solo->id)->exists())->toBeTrue();
});
