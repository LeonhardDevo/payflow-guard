<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Workspaces\InvitationService;

beforeEach(function (): void {
    config(['mail.default' => 'outbox']);
});

it('invites by email through the outbox (rendered, localized, referenced)', function (): void {
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $workspace->update(['locale' => 'ru']);

    $this->withSession(['payflow.stepup_at' => now()->getTimestamp(), 'payflow.stepup_method' => 'totp'])
        ->post(route('settings.members.invitations.store'), ['email' => 'New.Person@Example.com', 'role' => 'admin'])
        ->assertRedirect()
        ->assertSessionHas('success');

    $invitation = WorkspaceInvitation::query()->sole();
    expect($invitation->email)->toBe('new.person@example.com')
        ->and($invitation->role)->toBe(WorkspaceRole::Admin)
        ->and($invitation->expires_at->diffInDays(now()->addDays(7)))->toBeLessThan(1);

    $message = OutboxMessage::query()->topic('email.send')->sole();
    $payload = $message->payload;

    expect($payload['to'])->toBe('new.person@example.com')
        ->and($payload['locale'])->toBe('ru')
        ->and($payload['reference'])->toEqual(['type' => 'invitation', 'id' => (string) $invitation->id])
        ->and($payload['workspace_id'])->toBe($workspace->id)
        ->and($message->workspace_id)->toBe($workspace->id)
        ->and($payload['subject'])->toContain($workspace->name)
        ->and($payload['html'])->toContain('Принять приглашение')
        ->and($payload['html'])->toMatch('#/invitations/[A-Za-z0-9]{64}#')
        ->and($payload['text'])->toContain('Принять приглашение');

    expect(AuditLog::query()->where('action', 'invitation.created')->exists())->toBeTrue();
});

it('re-inviting replaces the previous pending invitation', function (): void {
    [$owner, $workspace] = $this->signInMember();
    $this->withSession(['payflow.stepup_at' => now()->getTimestamp(), 'payflow.stepup_method' => 'totp']);

    $first = app(InvitationService::class)->invite($workspace, $owner, 'x@example.com', WorkspaceRole::Member);
    $second = app(InvitationService::class)->invite($workspace, $owner, 'X@example.com', WorkspaceRole::Admin);

    expect(WorkspaceInvitation::query()->count())->toBe(1)
        ->and(WorkspaceInvitation::findByToken($first['token']))->toBeNull()
        ->and(WorkspaceInvitation::findByToken($second['token'])?->role)->toBe(WorkspaceRole::Admin);
});

it('refuses to invite existing members', function (): void {
    [, $workspace] = $this->signInMember();
    [$member] = $this->member(WorkspaceRole::Member, $workspace);

    $this->post(route('settings.members.invitations.store'), ['email' => strtoupper($member->email), 'role' => 'member'])
        ->assertSessionHas('error');

    expect(WorkspaceInvitation::query()->count())->toBe(0);
});

it('enforces the seats limit of the plan', function (): void {
    [, $workspace] = $this->signInMember();
    config(['billing.plans.free.limits.seats' => 2]);
    WorkspaceInvitation::factory()->create(['workspace_id' => $workspace->id]);

    $this->post(route('settings.members.invitations.store'), ['email' => 'third@example.com', 'role' => 'member'])
        ->assertSessionHas('error');

    expect(WorkspaceInvitation::query()->count())->toBe(1);
});

it('revokes an invitation of the current workspace only', function (): void {
    [, $workspace] = $this->signInMember();
    $mine = WorkspaceInvitation::factory()->create(['workspace_id' => $workspace->id]);
    $foreign = WorkspaceInvitation::factory()->create();

    $this->delete(route('settings.members.invitations.destroy', $foreign))->assertNotFound();
    $this->delete(route('settings.members.invitations.destroy', $mine))->assertSessionHas('success');

    expect($mine->fresh())->toBeNull()
        ->and($foreign->fresh())->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'invitation.revoked')->exists())->toBeTrue();
});

it('shows the invitation page to guests', function (): void {
    $invitation = WorkspaceInvitation::factory()->withToken($token = str_repeat('a', 64))->create(['email' => 'guest@example.com']);

    $this->get(route('invitations.show', $token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('invitations/Accept', false)
            ->where('invitation.email', 'guest@example.com')
            ->where('invitation.workspace.name', $invitation->workspace->name)
            ->where('invitation.role', 'member')
            ->where('status', 'pending')
            ->where('userExists', false)
            ->where('emailMatches', false));
});

it('404s unknown invitation tokens', function (): void {
    $this->get('/invitations/'.str_repeat('z', 64))->assertNotFound();
});

it('sends guests to register (new email) or login (existing account)', function (): void {
    $token = str_repeat('b', 64);
    WorkspaceInvitation::factory()->withToken($token)->create(['email' => 'fresh@example.com']);

    $this->post(route('invitations.accept', $token))
        ->assertRedirect(route('register'))
        ->assertSessionHas('url.intended', route('invitations.show', $token));

    User::factory()->create(['email' => 'fresh@example.com']);

    $this->post(route('invitations.accept', $token))->assertRedirect(route('login'));
});

it('accepts for the invited email, joins, switches and verifies the email', function (): void {
    $token = str_repeat('c', 64);
    $invitation = WorkspaceInvitation::factory()->withToken($token)->create([
        'email' => 'joiner@example.com',
        'role' => WorkspaceRole::Admin,
    ]);
    $user = User::factory()->unverified()->create(['email' => 'Joiner@example.com']);

    $this->actingAs($user)->post(route('invitations.accept', $token))->assertRedirect(route('dashboard'));

    $user->refresh();
    expect($user->roleIn($invitation->workspace_id))->toBe(WorkspaceRole::Admin)
        ->and($user->current_workspace_id)->toBe($invitation->workspace_id)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($invitation->refresh()->accepted_at)->not->toBeNull()
        ->and($invitation->accepted_by)->toBe($user->id)
        ->and(AuditLog::query()->where('action', 'invitation.accepted')->exists())->toBeTrue();

    $this->post(route('invitations.accept', $token))->assertSessionHas('error', __('notifications.errors.invitation_used'));
});

it('rejects accepting with another email or after expiry', function (): void {
    $token = str_repeat('d', 64);
    WorkspaceInvitation::factory()->withToken($token)->create(['email' => 'right@example.com']);

    $this->actingAs(User::factory()->create(['email' => 'wrong@example.com']))
        ->post(route('invitations.accept', $token))
        ->assertSessionHas('error', __('notifications.errors.invitation_email_mismatch', ['email' => 'right@example.com']));

    $expiredToken = str_repeat('e', 64);
    WorkspaceInvitation::factory()->withToken($expiredToken)->expired()->create(['email' => 'late@example.com']);

    $this->actingAs(User::factory()->create(['email' => 'late@example.com']))
        ->post(route('invitations.accept', $expiredToken))
        ->assertSessionHas('error', __('notifications.errors.invitation_expired'));
});

it('lets a newly registered invitee land back on the invitation', function (): void {
    $token = str_repeat('f', 64);
    WorkspaceInvitation::factory()->withToken($token)->create(['email' => 'reg@example.com']);

    $this->post(route('invitations.accept', $token));

    $this->post(route('register.store'), [
        'name' => 'Reg User',
        'email' => 'reg@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
    ])->assertRedirect(route('invitations.show', $token));
});
