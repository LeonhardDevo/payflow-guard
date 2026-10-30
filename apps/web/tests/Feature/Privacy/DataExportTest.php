<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\AiQuery;
use App\Models\AuditLog;
use App\Models\DataExport;
use App\Models\Document;
use App\Models\OutboxMessage;
use App\Privacy\DataExports;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    Storage::fake('documents');
    Storage::fake('local');
    config(['mail.default' => 'outbox']);
});

/** @return array<string, string> entry name => contents */
function zipEntries(string $disk, string $path): array
{
    $file = tempnam(sys_get_temp_dir(), 'zip-test-');
    file_put_contents($file, Storage::disk($disk)->get($path));
    $zip = new ZipArchive;
    $zip->open($file);
    $entries = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $entries[$name] = (string) $zip->getFromIndex($i);
    }

    $zip->close();
    @unlink($file);

    return $entries;
}

it('queues a personal export through the outbox and audits it', function (): void {
    [$user] = $this->signInMember();

    $this->post(route('settings.privacy.export'))->assertRedirect()->assertSessionHas('success');

    $export = DataExport::query()->sole();

    expect($export->scope)->toBe('user')
        ->and($export->status)->toBe('pending')
        ->and($export->user_id)->toBe($user->id)
        ->and(OutboxMessage::query()->topic('privacy.export.requested')->sole()->payload)->toBe(['export_id' => $export->id])
        ->and(AuditLog::query()->where('action', 'privacy.export_requested')->where('actor_id', $user->id)->exists())->toBeTrue();

    $this->post(route('settings.privacy.export'))->assertSessionHas('error', __('notifications.privacy.export_in_progress'));
    expect(DataExport::query()->count())->toBe(1);
});

it('builds a ZIP of JSON + owned files without secrets and e-mails a signed link', function (): void {
    [$user, $workspace] = $this->member(WorkspaceRole::Owner, attributes: ['name' => 'Export Person']);
    $user->forceFill(['two_factor_secret' => encrypt('secret-2fa')])->save();

    Storage::disk('documents')->put("{$workspace->id}/mine.pdf", 'PDF-BYTES-MINE');
    Storage::disk('documents')->put("{$workspace->id}/other.pdf", 'PDF-BYTES-OTHER');
    Document::factory()->create(['workspace_id' => $workspace->id, 'uploaded_by' => $user->id, 'path' => "{$workspace->id}/mine.pdf", 'original_name' => 'My Policy.pdf']);
    Document::factory()->create(['workspace_id' => $workspace->id, 'uploaded_by' => null, 'path' => "{$workspace->id}/other.pdf"]);
    AiQuery::query()->forceCreate(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'channel' => 'web', 'question' => 'What is our refund policy?']);

    $export = app(DataExports::class)->request($user, 'user');

    $this->internal('POST', "/internal/v1/privacy/exports/{$export->id}/build")
        ->assertOk()
        ->assertJsonPath('status', 'ready');

    $export->refresh();
    $entries = zipEntries($export->disk, $export->path);
    $account = json_decode($entries['account.json'], true);
    $all = implode("\n", $entries);

    expect(array_keys($entries))->toContain('manifest.json', 'account.json', 'workspaces.json', 'ai_queries.json', 'legal_acceptances.json', 'activity.json', 'documents.json')
        ->and($account['email'])->toBe($user->email)
        ->and($account['name'])->toBe('Export Person')
        ->and(collect(array_keys($entries))->first(fn ($name) => str_starts_with($name, 'files/')))->toEndWith('-my-policy.pdf')
        ->and($all)->toContain('PDF-BYTES-MINE')
        ->and($all)->not->toContain('PDF-BYTES-OTHER')
        ->and($all)->toContain('What is our refund policy?')
        ->and($all)->not->toContain($user->password)
        ->and($all)->not->toContain('two_factor_secret')
        ->and($export->expires_at->between(now()->addHours(23), now()->addHours(25)))->toBeTrue();

    $mail = OutboxMessage::query()->topic('email.send')->sole()->payload;
    expect($mail['to'])->toBe($user->email)
        ->and($mail['reference'])->toEqual(['type' => 'data_export', 'id' => (string) $export->id])
        ->and($mail['html'])->toContain('/settings/privacy/exports/'.$export->id.'/download')
        ->and($mail['html'])->toContain('signature=')
        ->and(AuditLog::query()->where('action', 'privacy.export_ready')->exists())->toBeTrue();

    $this->internal('POST', "/internal/v1/privacy/exports/{$export->id}/build")->assertOk();
    expect(OutboxMessage::query()->topic('email.send')->count())->toBe(1);
});

it('downloads only for the owner through a valid signed link', function (): void {
    [$user] = $this->member();
    $export = app(DataExports::class)->request($user, 'user');
    app(DataExports::class)->build($export->refresh());
    $url = app(DataExports::class)->downloadUrl($export->refresh());

    $this->actingAs($user)->get(route('settings.privacy.exports.download', $export))->assertForbidden();

    $response = $this->actingAs($user)->get($url);
    $response->assertOk()->assertDownload();
    expect($export->refresh()->downloaded_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'privacy.export_downloaded')->exists())->toBeTrue();

    [$stranger] = $this->member();
    $this->actingAs($stranger)->get($url)->assertNotFound();
});

it('expires the link after 24 hours and purges the archive', function (): void {
    [$user] = $this->member();
    $export = app(DataExports::class)->request($user, 'user');
    app(DataExports::class)->build($export->refresh());
    $export->refresh();
    $path = $export->path;

    $this->travel(25)->hours();

    $url = URL::signedRoute('settings.privacy.exports.download', ['export' => $export->id]);
    $this->actingAs($user)->get($url)->assertRedirect(route('settings.privacy'))->assertSessionHas('error');

    expect(app(DataExports::class)->purgeExpired())->toBe(1)
        ->and($export->refresh()->status)->toBe('expired')
        ->and($export->path)->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

it('exports a workspace for owners only, with every document file', function (): void {
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    Storage::disk('documents')->put("{$workspace->id}/a.pdf", 'WS-FILE');
    Document::factory()->create(['workspace_id' => $workspace->id, 'path' => "{$workspace->id}/a.pdf"]);

    $this->post(route('settings.workspace.export'))->assertSessionHas('success');
    $export = DataExport::query()->sole();
    expect($export->scope)->toBe('workspace')->and($export->workspace_id)->toBe($workspace->id);

    app(DataExports::class)->build($export);
    $entries = zipEntries($export->disk, $export->refresh()->path);

    expect(array_keys($entries))->toContain('workspace.json', 'members.json', 'documents.json', 'audit_log.json')
        ->and(implode('', $entries))->toContain('WS-FILE')
        ->and(json_decode($entries['members.json'], true)[0]['email'])->toBe($owner->email);

    [$admin] = $this->member(WorkspaceRole::Admin, $workspace);
    $this->actingAs($admin)->post(route('settings.workspace.export'))->assertForbidden();
});

it('marks a failing build as failed so the worker retries', function (): void {
    [$user, $workspace] = $this->member(WorkspaceRole::Owner);
    $export = DataExport::query()->create(['user_id' => $user->id, 'workspace_id' => $workspace->id, 'scope' => 'workspace', 'status' => 'pending']);
    $workspace->delete();

    expect(DataExport::query()->find($export->id))->toBeNull();

    $orphan = DataExport::query()->create(['user_id' => $user->id, 'workspace_id' => null, 'scope' => 'workspace', 'status' => 'pending']);
    $this->internal('POST', "/internal/v1/privacy/exports/{$orphan->id}/build")->assertStatus(500)->assertJsonPath('status', 'failed');

    expect(AuditLog::query()->where('action', 'privacy.export_failed')->exists())->toBeTrue();
});

it('rejects unsigned or non-worker calls to the internal export endpoint', function (): void {
    [$user] = $this->member();
    $export = app(DataExports::class)->request($user, 'user');

    $this->postJson("/internal/v1/privacy/exports/{$export->id}/build")->assertUnauthorized();
    $this->internal('POST', "/internal/v1/privacy/exports/{$export->id}/build", service: 'ai')->assertForbidden();
});
