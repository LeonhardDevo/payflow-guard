<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\TelegramAccount;
use App\Models\WorkspaceInvitation;
use App\Privacy\DeletionRequests;

it('lists members, pending invitations and roles', function (): void {
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    [$member] = $this->member(WorkspaceRole::Member, $workspace);
    TelegramAccount::query()->create([
        'user_id' => $member->id, 'telegram_user_id' => 555, 'chat_id' => 555, 'linked_at' => now(),
    ]);
    WorkspaceInvitation::factory()->create(['workspace_id' => $workspace->id, 'email' => 'new@example.com', 'invited_by' => $owner->id]);
    WorkspaceInvitation::factory()->expired()->create(['workspace_id' => $workspace->id]);

    $this->get(route('settings.members'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/Members', false)
            ->has('members', 2)
            ->where('members', fn ($members) => collect($members)->firstWhere('id', $member->id)['telegram_linked'] === true
                && collect($members)->firstWhere('id', $owner->id)['is_you'] === true
                && collect($members)->firstWhere('id', $owner->id)['role'] === 'owner')
            ->has('invitations', 1)
            ->where('invitations.0.email', 'new@example.com')
            ->where('invitations.0.invited_by', $owner->name)
            ->has('roles', 6)
            ->where('assignableRoles', ['owner', 'admin', 'finance', 'approver', 'member', 'external_approver']));
});

it('lets an owner change roles and audits it', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    [$member] = $this->member(WorkspaceRole::Member, $workspace);

    $this->withSession(['payflow.stepup_at' => now()->getTimestamp(), 'payflow.stepup_method' => 'totp'])
        ->patch(route('settings.members.update', $member), ['role' => 'admin'])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($member->roleIn($workspace))->toBe(WorkspaceRole::Admin);

    $log = AuditLog::query()->where('action', 'member.role_changed')->sole();
    expect($log->changes)->toBe(['role' => ['member', 'admin']])
        ->and($log->workspace_id)->toBe($workspace->id);
});

it('stops admins from promoting to admin/owner or touching owners', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Admin);
    [$member] = $this->member(WorkspaceRole::Member, $workspace);
    [$owner] = $this->member(WorkspaceRole::Owner, $workspace);

    $this->patch(route('settings.members.update', $member), ['role' => 'owner'])->assertSessionHas('error');
    $this->patch(route('settings.members.update', $member), ['role' => 'admin'])->assertSessionHas('error');
    $this->patch(route('settings.members.update', $owner), ['role' => 'member'])->assertSessionHas('error');
    $this->delete(route('settings.members.destroy', $owner))->assertSessionHas('error');

    expect($member->roleIn($workspace))->toBe(WorkspaceRole::Member)
        ->and($owner->roleIn($workspace))->toBe(WorkspaceRole::Owner);
});

it('keeps at least one owner', function (): void {
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    [$coOwner] = $this->member(WorkspaceRole::Owner, $workspace);

    $this->withSession(['payflow.stepup_at' => now()->getTimestamp(), 'payflow.stepup_method' => 'totp'])
        ->patch(route('settings.members.update', $coOwner), ['role' => 'admin'])->assertSessionHas('success');

    $this->delete(route('settings.members.destroy', $owner))
        ->assertSessionHas('error', __('notifications.errors.last_owner'));

    expect($owner->roleIn($workspace))->toBe(WorkspaceRole::Owner);
});

it('prevents changing your own role', function (): void {
    [$owner] = $this->signInMember(WorkspaceRole::Owner);

    $this->patch(route('settings.members.update', $owner), ['role' => 'member'])
        ->assertSessionHas('error', __('notifications.errors.cannot_change_own_role'));
});

it('removes a member, clears their pointers and audits it', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Admin);
    [$member] = $this->member(WorkspaceRole::Member, $workspace);
    TelegramAccount::query()->create([
        'user_id' => $member->id, 'workspace_id' => $workspace->id, 'telegram_user_id' => 77, 'chat_id' => 77, 'linked_at' => now(),
    ]);

    $this->delete(route('settings.members.destroy', $member))->assertSessionHas('success');

    expect($member->roleIn($workspace))->toBeNull()
        ->and($member->refresh()->current_workspace_id)->toBeNull()
        ->and(TelegramAccount::query()->where('user_id', $member->id)->value('workspace_id'))->toBeNull()
        ->and(AuditLog::query()->where('action', 'member.removed')->exists())->toBeTrue();
});

it('lets a member leave the workspace', function (): void {
    [$member, $workspace] = $this->signInMember(WorkspaceRole::Member);
    $this->member(WorkspaceRole::Owner, $workspace);

    $this->delete(route('settings.members.destroy', $member))->assertRedirect(route('dashboard'));

    expect($member->roleIn($workspace))->toBeNull()
        ->and(AuditLog::query()->where('action', 'member.left')->exists())->toBeTrue();
});

it('validates the role value', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    [$member] = $this->member(WorkspaceRole::Member, $workspace);

    $this->patch(route('settings.members.update', $member), ['role' => 'god'])->assertSessionHasErrors('role');
});

it('updates workspace settings with an audited diff', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Admin);

    $this->get(route('settings.workspace'))
        ->assertInertia(fn ($page) => $page
            ->component('settings/Workspace', false)
            ->where('workspace.id', $workspace->id)
            ->has('timezones')
            ->has('currencies'));

    $this->patch(route('settings.workspace.update'), [
        'name' => 'Renamed',
        'timezone' => 'Asia/Almaty',
        'currency' => 'kzt',
        'locale' => 'ru',
    ])->assertSessionHas('success');

    expect($workspace->refresh()->only(['name', 'timezone', 'currency', 'locale']))->toBe([
        'name' => 'Renamed', 'timezone' => 'Asia/Almaty', 'currency' => 'KZT', 'locale' => 'ru',
    ]);

    expect(AuditLog::query()->where('action', 'workspace.updated')->sole()->changes)
        ->toHaveKeys(['name', 'timezone', 'currency', 'locale']);
});

it('deletes a workspace only for owners with the right password', function (): void {
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Owner);

    $this->delete(route('settings.workspace.destroy'), ['password' => 'wrong'])->assertSessionHasErrors('password');
    expect($workspace->fresh())->not->toBeNull();

    $this->delete(route('settings.workspace.destroy'), ['password' => 'password'])
        ->assertRedirect(route('settings.privacy'));

    expect($workspace->fresh())->not->toBeNull();
    $this->travel(31)->days();
    app(DeletionRequests::class)->runDue();

    expect($workspace->fresh())->toBeNull()
        ->and(AuditLog::query()->where('action', 'workspace.deleted')->where('workspace_id', $workspace->id)->exists())->toBeTrue();
});

it('forbids admins from deleting the workspace', function (): void {
    $this->signInMember(WorkspaceRole::Admin);

    $this->delete(route('settings.workspace.destroy'), ['password' => 'password'])->assertForbidden();
});
