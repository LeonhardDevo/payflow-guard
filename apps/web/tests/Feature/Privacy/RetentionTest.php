<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\AiQuery;
use App\Models\AuditLog;
use App\Models\Workspace;
use App\Privacy\PrivacyPurge;
use App\Privacy\RetentionPolicy;
use App\Privacy\RetentionPurger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function aiQueryAt(Workspace $workspace, int $daysAgo): void
{
    AiQuery::query()->forceCreate([
        'workspace_id' => $workspace->id,
        'channel' => 'web',
        'question' => 'q'.$daysAgo,
        'created_at' => now()->subDays($daysAgo),
    ]);
}

function auditAt(?int $workspaceId, int $daysAgo): void
{
    DB::table('audit_logs')->insert([
        'workspace_id' => $workspaceId,
        'action' => 'test.entry',
        'created_at' => now()->subDays($daysAgo),
    ]);
}

it('purges each category by its retention and reports counts', function (): void {
    [, $workspace] = $this->member(WorkspaceRole::Owner);

    aiQueryAt($workspace, 181);
    aiQueryAt($workspace, 10);
    auditAt($workspace->id, 400);
    auditAt($workspace->id, 30);
    auditAt(null, 366);
    auditAt(999_999, 400);
    DB::table('deliveries')->insert([
        ['reference_type' => 'mail', 'reference_id' => '1', 'channel' => 'email', 'status' => 'sent', 'created_at' => now()->subDays(91)],
        ['reference_type' => 'mail', 'reference_id' => '2', 'channel' => 'email', 'status' => 'sent', 'created_at' => now()->subDays(5)],
    ]);
    DB::table('outbox_messages')->insert([
        ['id' => (string) Str::uuid(), 'topic' => 't', 'payload' => '{}', 'dispatched_at' => now()->subDays(8)],
        ['id' => (string) Str::uuid(), 'topic' => 't', 'payload' => '{}', 'dispatched_at' => now()->subDay()],
        ['id' => (string) Str::uuid(), 'topic' => 't', 'payload' => '{}', 'dispatched_at' => null],
    ]);
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'c', 'queue' => 'q', 'payload' => '{}', 'exception' => 'e', 'failed_at' => now()->subDays(31),
    ]);

    $counts = app(RetentionPurger::class)->run();

    expect($counts)->toMatchArray([
        'ai_queries' => 1,
        'audit_logs' => 3,
        'deliveries' => 1,
        'outbox_dispatched' => 1,
        'failed_jobs' => 1,
        'data_exports' => 0,
    ])
        ->and(AiQuery::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(DB::table('outbox_messages')->count())->toBe(2);
});

it('honours per-workspace overrides within bounds and plan caps', function (): void {
    [, $short] = $this->member(WorkspaceRole::Owner);
    [, $default] = $this->member(WorkspaceRole::Owner);
    $short->forceFill(['retention' => ['ai_queries' => 30]])->save();

    aiQueryAt($short, 31);
    aiQueryAt($default, 31);

    expect(app(RetentionPurger::class)->run()['ai_queries'])->toBe(1)
        ->and(AiQuery::query()->withoutGlobalScopes()->where('workspace_id', $default->id)->count())->toBe(1);

    $policy = app(RetentionPolicy::class);
    $short->forceFill(['retention' => ['ai_queries' => 700]])->save();
    expect($policy->days('ai_queries', $short->fresh()))->toBe(180)
        ->and($policy->bounds('ai_queries', $short->fresh()))->toBe(['min' => 7, 'max' => 180]);
});

it('runs from the worker scheduler endpoint and audits the counts', function (): void {
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    aiQueryAt($workspace, 200);

    $this->internal('POST', '/internal/v1/privacy/purge')
        ->assertOk()
        ->assertJsonPath('retention.ai_queries', 1)
        ->assertJsonPath('deletions.users', 0)
        ->assertJsonPath('webhook_events_days', 30)
        ->assertJsonPath('ai_memory.default_days', app(RetentionPolicy::class)->days('ai_queries'))
        ->assertJsonPath('skipped', false);

    $entry = AuditLog::query()->where('action', 'privacy.retention_purged')->sole();
    expect($entry->workspace_id)->toBeNull()
        ->and($entry->actor_id)->toBeNull()
        ->and($entry->metadata['retention']['ai_queries'])->toBe(1);

    $this->internal('POST', '/internal/v1/privacy/purge', service: 'ai')->assertForbidden();
});

it('reports per-workspace ai memory retention that differs from the default', function (): void {
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    [, $other] = $this->member(WorkspaceRole::Owner);
    $workspace->forceFill(['retention' => ['ai_queries' => 7]])->save();

    $memory = app(PrivacyPurge::class)->run()['ai_memory'] ?? null;

    expect($memory)->not->toBeNull()
        ->and($memory['workspaces'])->toContain(['workspace_id' => $workspace->id, 'days' => 7])
        ->and(collect($memory['workspaces'])->pluck('workspace_id'))->not->toContain($other->id);
});

it('exposes the purge as an artisan command', function (): void {
    $this->artisan('privacy:purge')->assertSuccessful();
    expect(AuditLog::query()->where('action', 'privacy.retention_purged')->exists())->toBeTrue();
});
