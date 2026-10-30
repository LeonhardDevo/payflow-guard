<?php

declare(strict_types=1);

use App\Audit\Auditor;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('records successful login with ip, user agent and request id', function (): void {
    [$user, $workspace] = $this->member();

    $this->withHeader('User-Agent', 'PestBrowser/1.0')
        ->withHeader('X-Request-Id', 'login-request-123')
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $log = AuditLog::query()->where('action', 'auth.login')->sole();

    expect($log->actor_id)->toBe($user->id)
        ->and($log->workspace_id)->toBe($workspace->id)
        ->and($log->ip)->toBe('127.0.0.1')
        ->and($log->user_agent)->toBe('PestBrowser/1.0')
        ->and($log->request_id)->toBe('login-request-123');
});

it('records failed logins without the password', function (): void {
    [$user] = $this->member();

    $this->post(route('login.store'), ['email' => strtoupper($user->email), 'password' => 'definitely-wrong']);

    $log = AuditLog::query()->where('action', 'auth.login_failed')->sole();

    expect($log->actor_id)->toBeNull()
        ->and($log->metadata)->toBe(['email' => strtolower($user->email)])
        ->and(json_encode($log->toArray()))->not->toContain('definitely-wrong');
});

it('records logout', function (): void {
    [$user] = $this->signInMember();

    $this->post(route('logout'));

    expect(AuditLog::query()->where('action', 'auth.logout')->where('actor_id', $user->id)->exists())->toBeTrue();
});

it('is append-only at the model and the database level', function (): void {
    $log = app(Auditor::class)->record('workspace.created', Workspace::factory()->create());

    expect(fn () => $log->update(['action' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class);

    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'tampered'])))
        ->toThrow(QueryException::class, 'append-only');

    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->where('id', $log->id)->delete()))
        ->toThrow(QueryException::class, 'append-only');

    expect(AuditLog::query()->find($log->id)?->action)->toBe('workspace.created');
});

it('redacts secret-looking keys in changes and metadata', function (): void {
    $log = app(Auditor::class)->record(
        'ai.key_updated',
        changes: ['api_key' => ['old-secret', 'new-secret'], 'name' => ['a', 'b']],
        metadata: ['token' => 'abc', 'nested' => ['password' => 'p', 'ok' => 1]],
        platform: true,
    );

    expect($log->changes)->toBe(['api_key' => ['[redacted]', '[redacted]'], 'name' => ['a', 'b']])
        ->and($log->metadata)->toBe(['token' => '[redacted]', 'nested' => ['password' => '[redacted]', 'ok' => 1]])
        ->and($log->workspace_id)->toBeNull();
});

it('builds diffs including enums', function (): void {
    expect(Auditor::diff(['role' => WorkspaceRole::Member, 'name' => 'x'], ['role' => 'admin', 'name' => 'x']))
        ->toBe(['role' => ['member', 'admin']]);
});

it('shows the workspace audit log with filters', function (): void {
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Admin);
    $auditor = app(Auditor::class);
    $other = User::factory()->create(['name' => 'Zed']);

    $auditor->record('workspace.updated', $workspace, ['name' => ['A', 'B']], actor: $owner);
    $auditor->record('member.role_changed', $other, ['role' => ['member', 'admin']], actor: $other, workspace: $workspace);
    $auditor->record('member.removed', $other, actor: $owner, workspace: $workspace);
    $auditor->record('workspace.updated', Workspace::factory()->create(), actor: $owner);

    $this->get(route('audit.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('audit/Index', false)
            ->has('logs.data', 3)
            ->where('logs.data.0.action', 'member.removed')
            ->where('logs.data.0.action_label', __('audit.actions.member.removed'))
            ->where('logs.data.2.changes', ['name' => ['A', 'B']])
            ->where('actions', ['member.removed', 'member.role_changed', 'workspace.updated'])
            ->has('actors', 2));

    $this->get(route('audit.index', ['action' => 'member.*']))
        ->assertInertia(fn ($page) => $page->has('logs.data', 2));

    $this->get(route('audit.index', ['actor' => $other->id]))
        ->assertInertia(fn ($page) => $page->has('logs.data', 1)->where('logs.data.0.actor.name', 'Zed'));

    $this->get(route('audit.index', ['from' => now()->addDay()->toDateString()]))
        ->assertInertia(fn ($page) => $page->has('logs.data', 0));

    $this->get(route('audit.index', ['from' => '2026-02-01', 'to' => '2026-01-01']))->assertSessionHasErrors('to');
});
