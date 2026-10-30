<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\OutboxMessage;
use App\Models\TelegramAccount;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

it('renders Settings → Privacy with the contract props', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);

    $this->get(route('settings.privacy'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/Privacy', false)
            ->where('exports', [])
            ->where('deletion.scheduled_for', null)
            ->where('deletion.grace_days', 14)
            ->has('acceptances')
            ->where('privacy_workspace.id', $workspace->id)
            ->where('privacy_workspace.ai_external_allowed', false)
            ->where('privacy_workspace.retention', ['ai_queries' => 180, 'audit_logs' => 365])
            ->where('privacy_workspace.retention_bounds.ai_queries', ['min' => 7, 'max' => 180])
            ->where('privacy_workspace.deletion.scheduled_for', null)
            ->where('privacy_can', ['manage_workspace_privacy' => true, 'export_workspace' => true, 'delete_workspace' => true])
            ->where('export_link_hours', 24)
            ->etc());
});

it('is reachable without a workspace (export and deletion rights)', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('settings.privacy'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('privacy_workspace', null)->where('privacy_can.delete_workspace', false)->etc());
});

it('lets owners and admins change AI data controls and retention, audited', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Admin);

    $this->patch(route('settings.privacy.workspace'), [
        'ai_external_allowed' => true,
        'retention' => ['ai_queries' => 30],
    ])->assertSessionHas('success');

    $workspace->refresh();
    expect($workspace->ai_external_allowed)->toBeTrue()
        ->and($workspace->retention)->toBe(['ai_queries' => 30])
        ->and(AuditLog::query()->where('action', 'privacy.settings_updated')->sole()->changes)
        ->toHaveKeys(['ai_external_allowed', 'retention'])
        ->and(OutboxMessage::query()->topic('workspace.reembed')->sole()->payload)
        ->toBe(['workspace_id' => $workspace->id, 'ai_external_allowed' => true]);

    $this->patch(route('settings.privacy.workspace'), ['retention' => ['ai_queries' => 3]])->assertSessionHasErrors('retention.ai_queries');
    $this->patch(route('settings.privacy.workspace'), ['retention' => ['ai_queries' => 365]])->assertSessionHasErrors('retention.ai_queries');
    $this->patch(route('settings.privacy.workspace'), ['retention' => ['deliveries' => 30]])->assertSessionHasErrors('retention');

    $this->patch(route('settings.privacy.workspace'), ['retention' => ['ai_queries' => null]]);
    expect($workspace->fresh()->retention)->toBe([]);
});

it('forbids members from changing workspace privacy settings', function (): void {
    $this->signInMember(WorkspaceRole::Member);

    $this->patch(route('settings.privacy.workspace'), ['ai_external_allowed' => true])->assertForbidden();
});

it('sends the workspace AI data control with every assistant question', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    Http::fake(['ai.test/v1/chat' => Http::response(['answer_markdown' => 'ok', 'answer_html' => '<p>ok</p>', 'citations' => [], 'confidence' => 1, 'refused' => false])]);

    $this->postJson(route('assistant.ask'), ['message' => 'Hello?'])->assertOk();
    Http::assertSent(fn (HttpRequest $request): bool => $request['ai_external_allowed'] === false);

    $workspace->forceFill(['ai_external_allowed' => true])->save();
    $this->postJson(route('assistant.ask'), ['message' => 'Hello again?'])->assertOk();
    Http::assertSent(fn (HttpRequest $request): bool => $request['ai_external_allowed'] === true);
});

it('gives the bot the AI data control and unlinks on /unlink', function (): void {
    [$user, $workspace] = $this->member(WorkspaceRole::Owner);
    TelegramAccount::query()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'telegram_user_id' => 777,
        'chat_id' => 777,
        'username' => 'privacy_user',
        'linked_at' => now(),
    ]);

    $this->internal('GET', '/internal/v1/telegram/context?telegram_user_id=777')
        ->assertOk()
        ->assertJsonPath('workspace.ai_external_allowed', false);

    $this->internal('POST', '/internal/v1/telegram/unlink', ['telegram_user_id' => 777])
        ->assertOk()
        ->assertJsonPath('unlinked', true);

    expect(TelegramAccount::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'telegram.unlinked')->exists())->toBeTrue();

    $this->internal('POST', '/internal/v1/telegram/unlink', ['telegram_user_id' => 777])->assertNotFound();
    $this->internal('POST', '/internal/v1/telegram/unlink', [])->assertUnprocessable();
});

it('publishes security.txt and robots.txt', function (): void {
    config(['legal.security.contact' => 'mailto:sec@fixture.test']);

    $body = $this->get('/.well-known/security.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->getContent();

    expect($body)->toContain('Contact: mailto:sec@fixture.test')
        ->and($body)->toMatch('/^Expires: \d{4}-\d{2}-\d{2}T00:00:00Z$/m')
        ->and($body)->toContain('Preferred-Languages: en, ru')
        ->and($body)->toContain('Policy: '.route('security'));

    $robots = $this->get('/robots.txt')->assertOk()->getContent();
    expect($robots)->toContain('User-agent: *')
        ->and($robots)->toContain('Disallow: /settings')
        ->and($robots)->toContain('Disallow: /internal/');
});
