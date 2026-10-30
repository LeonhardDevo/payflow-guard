<?php

declare(strict_types=1);

use App\Audit\AuditMaintenance;
use App\Audit\Auditor;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\DeletionRequest;
use App\Models\Document;
use App\Models\OutboxMessage;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Outbox\Outbox;
use App\Privacy\DeletionRequests;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => config(['mail.default' => 'outbox']));

it('schedules account deletion after re-authentication with a 14-day grace period', function (): void {
    [$user] = $this->signInMember(WorkspaceRole::Member);

    $this->post(route('settings.privacy.delete-account'), ['password' => 'wrong'])->assertSessionHasErrors('password');
    expect(DeletionRequest::query()->count())->toBe(0);

    $this->post(route('settings.privacy.delete-account'), ['password' => 'password'])->assertSessionHas('success');

    $request = DeletionRequest::query()->sole();
    expect($request->subject_type)->toBe('user')
        ->and($request->subject_id)->toBe($user->id)
        ->and($request->scheduled_for->isSameDay(now()->addDays(14)))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'privacy.deletion_scheduled')->exists())->toBeTrue()
        ->and(OutboxMessage::query()->topic('email.send')->sole()->payload['reference']['type'])->toBe('deletion_request');

    $this->get(route('settings.privacy'))
        ->assertInertia(fn ($page) => $page->where('deletion.scheduled_for', $request->scheduled_for->toIso8601String())->etc());

    $this->post(route('settings.privacy.delete-account'), ['password' => 'password']);
    expect(DeletionRequest::query()->count())->toBe(1);
});

it('refuses while the user is the sole owner of a workspace with members', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $this->member(WorkspaceRole::Member, $workspace);

    $this->post(route('settings.privacy.delete-account'), ['password' => 'password'])
        ->assertSessionHas('error', __('notifications.privacy.transfer_ownership_first', ['workspace' => $workspace->name]));

    expect(DeletionRequest::query()->count())->toBe(0);
});

it('cancels a scheduled account deletion', function (): void {
    [$user] = $this->signInMember(WorkspaceRole::Member);
    $this->post(route('settings.privacy.delete-account'), ['password' => 'password']);

    $this->delete(route('settings.privacy.delete-account.cancel'))->assertSessionHas('success');
    expect(DeletionRequest::query()->sole()->cancelled_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'privacy.deletion_cancelled')->exists())->toBeTrue();

    $this->delete(route('settings.privacy.delete-account.cancel'))->assertSessionHas('error', __('notifications.privacy.nothing_to_cancel'));

    $this->travel(15)->days();
    app(DeletionRequests::class)->runDue();
    expect($user->fresh())->not->toBeNull();
});

it('hard-deletes the account after the grace period and pseudonymizes the audit trail', function (): void {
    [$user, $workspace] = $this->member(WorkspaceRole::Member);
    [$owner] = $this->member(WorkspaceRole::Owner, $workspace);
    $this->actingAs($user);
    $userId = $user->id;
    $email = $user->email;

    app(Auditor::class)->record('document.created', $workspace, actor: $user, subjectLabel: 'doc');
    TelegramAccount::query()->create(['user_id' => $userId, 'workspace_id' => $workspace->id, 'telegram_user_id' => 770001, 'chat_id' => 770001, 'linked_at' => now()]);
    app(DeletionRequests::class)->scheduleUser($user);

    $this->travel(13)->days();
    expect(app(DeletionRequests::class)->runDue()['users'])->toBe(0)
        ->and(User::query()->find($userId))->not->toBeNull();

    $this->travel(2)->days();
    expect(app(DeletionRequests::class)->runDue())->toMatchArray(['users' => 1, 'blocked' => 0, 'failed' => 0]);

    expect(User::query()->find($userId))->toBeNull()
        ->and($workspace->fresh())->not->toBeNull()
        ->and(DeletionRequest::query()->sole()->completed_at)->not->toBeNull()
        ->and(AuditLog::query()->where('actor_id', $userId)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('actor_name', AuditMaintenance::pseudonym($userId))->exists())->toBeTrue()
        ->and(AuditLog::query()->where('subject_label', $email)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'privacy.deletion_completed')->exists())->toBeTrue();

    expect($owner->fresh())->not->toBeNull()
        ->and(OutboxMessage::query()->topic('user.purge')->sole()->payload)->toBe(['user_id' => $userId, 'telegram_user_ids' => [770001]]);

    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->where('id', '>', 0)->update(['actor_name' => 'x'])))
        ->toThrow(QueryException::class, 'append-only');
});

it('keeps a due account deletion pending while the user became a sole owner with members', function (): void {
    [$user, $workspace] = $this->member(WorkspaceRole::Owner);
    app(DeletionRequests::class)->scheduleUser($user);
    $this->member(WorkspaceRole::Member, $workspace);

    $this->travel(15)->days();

    expect(app(DeletionRequests::class)->runDue())->toMatchArray(['users' => 0, 'blocked' => 1])
        ->and($user->fresh())->not->toBeNull()
        ->and(DeletionRequest::query()->pending()->count())->toBe(1);
});

it('schedules workspace deletion for owners only and cancels it', function (): void {
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    [$admin] = $this->member(WorkspaceRole::Admin, $workspace);

    $this->actingAs($admin)->post(route('settings.workspace.delete'), ['password' => 'password'])->assertForbidden();

    $this->actingAs($owner)->post(route('settings.workspace.delete'), ['password' => 'wrong'])->assertSessionHasErrors('password');
    $this->post(route('settings.workspace.delete'), ['password' => 'password'])->assertSessionHas('success');

    $request = DeletionRequest::query()->sole();
    expect($request->subject_type)->toBe('workspace')
        ->and($request->scheduled_for->isSameDay(now()->addDays(30)))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'workspace.deletion_scheduled')->where('workspace_id', $workspace->id)->exists())->toBeTrue();

    $this->get(route('settings.privacy'))
        ->assertInertia(fn ($page) => $page->where('privacy_workspace.deletion.scheduled_for', $request->scheduled_for->toIso8601String())->etc());

    $this->actingAs($admin)->delete(route('settings.workspace.delete.cancel'))->assertForbidden();
    $this->actingAs($owner)->delete(route('settings.workspace.delete.cancel'))->assertSessionHas('success');
    expect(DeletionRequest::query()->pending()->count())->toBe(0);
});

it('purges the workspace after 30 days: rows, files, queued jobs and RAG data', function (): void {
    Storage::fake('documents');
    [$owner, $workspace] = $this->member(WorkspaceRole::Owner);
    Storage::disk('documents')->put("{$workspace->id}/a.pdf", 'X');
    Document::factory()->create(['workspace_id' => $workspace->id, 'path' => "{$workspace->id}/a.pdf"]);
    app(Outbox::class)->publish('telegram.notify', ['text' => 'queued'], $workspace->id);

    app(DeletionRequests::class)->scheduleWorkspace($workspace, $owner);

    $this->travel(29)->days();
    expect(app(DeletionRequests::class)->runDue()['workspaces'])->toBe(0);

    $this->travel(2)->days();
    expect(app(DeletionRequests::class)->runDue()['workspaces'])->toBe(1);

    expect(Workspace::query()->find($workspace->id))->toBeNull()
        ->and(Document::query()->withoutGlobalScopes()->where('workspace_id', $workspace->id)->exists())->toBeFalse()
        ->and(OutboxMessage::query()->topic('telegram.notify')->exists())->toBeFalse()
        ->and(OutboxMessage::query()->topic('workspace.purge')->sole()->payload)->toBe(['workspace_id' => $workspace->id])
        ->and(AuditLog::query()->where('action', 'privacy.workspace_purged')->exists())->toBeTrue()
        ->and($owner->fresh())->not->toBeNull();

    Storage::disk('documents')->assertMissing("{$workspace->id}/a.pdf");
});
