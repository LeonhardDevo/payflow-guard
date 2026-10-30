<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

function platformAdmin(object $test): User
{
    [$user] = $test->member(WorkspaceRole::Owner);
    $user->forceFill(['is_platform_admin' => true])->save();
    $test->actingAs($user);

    return $user;
}

function fakeAiAdmin(): void
{
    Http::fake([
        'ai.test/v1/admin/ai/providers?*' => Http::response([
            ['id' => 'mock', 'label' => 'Mock', 'configured' => true, 'needs_key' => false, 'key_source' => null, 'key_hint' => null, 'default_model' => 'mock', 'models' => ['mock']],
            ['id' => 'openai', 'label' => 'OpenAI', 'configured' => false, 'needs_key' => true, 'key_source' => null, 'key_hint' => null, 'default_model' => 'gpt-5', 'models' => []],
        ]),
        'ai.test/v1/admin/ai/settings' => Http::sequence()
            ->push(['provider' => 'mock', 'model' => 'mock', 'fallback_model' => null, 'temperature' => 0.2, 'embeddings' => ['provider' => 'hash', 'model' => 'hash-384']])
            ->whenEmpty(Http::response(['provider' => 'openai', 'model' => 'gpt-5', 'fallback_model' => 'gpt-5-mini', 'temperature' => 0.1, 'embeddings' => ['provider' => 'local', 'model' => 'intfloat/multilingual-e5-small'], 'reembed_required' => true])),
        'ai.test/v1/admin/ai/usage*' => Http::response([
            'days' => [['date' => '2026-10-21', 'prompt_tokens' => 100, 'completion_tokens' => 50, 'cost_usd' => 0.01, 'requests' => 3]],
            'by_model' => [['provider' => 'mock', 'model' => 'mock', 'requests' => 3, 'cost_usd' => 0.01]],
            'total_cost_usd' => 0.01,
        ]),
        'ai.test/v1/admin/ai/keys/*' => Http::response(['provider' => 'openai', 'configured' => true, 'key_source' => 'settings', 'key_hint' => '…abcd']),
        'ai.test/v1/admin/ai/providers/*/models' => Http::response(['models' => ['gpt-5', 'gpt-5-mini']]),
        'ai.test/v1/admin/ai/test' => Http::response(['ok' => true, 'latency_ms' => 42, 'model' => 'mock']),
    ]);
}

it('renders providers, settings and usage from the ai service', function (): void {
    fakeAiAdmin();
    platformAdmin($this);

    $this->get(route('admin.ai'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/ai/Index', false)
            ->has('providers', 2)
            ->where('providers.0.id', 'mock')
            ->where('settings.provider', 'mock')
            ->where('settings.embeddings.provider', 'hash')
            ->where('usage.total_cost_usd', 0.01)
            ->where('embeddingsProviders', ['local', 'openai-compatible', 'hash'])
            ->where('serviceError', null));

    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/v1/admin/ai/usage?days=30')
        && $request->hasHeader('X-Signature'));
    Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/v1/admin/ai/providers?locale=en'));
});

it('degrades gracefully when the ai service is down', function (): void {
    Http::fake(fn () => throw new ConnectionException('refused'));
    platformAdmin($this);

    $this->get(route('admin.ai'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('providers', [])
            ->where('settings.provider', 'mock')
            ->where('serviceError', __('notifications.ai.unavailable')));
});

it('proxies settings updates and audits the diff at platform level', function (): void {
    fakeAiAdmin();
    platformAdmin($this);

    $this->put(route('admin.ai.settings'), [
        'provider' => 'openai',
        'model' => 'gpt-5',
        'fallback_model' => 'gpt-5-mini',
        'temperature' => 0.1,
        'embeddings' => ['provider' => 'local', 'model' => 'intfloat/multilingual-e5-small'],
    ])->assertRedirect()->assertSessionHas('success');

    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/v1/admin/ai/settings')
        && $request['provider'] === 'openai'
        && $request['embeddings'] === ['provider' => 'local', 'model' => 'intfloat/multilingual-e5-small']);

    expect(OutboxMessage::query()->topic('workspace.reembed')->pluck('payload')->all())
        ->toEqual(Workspace::query()->orderBy('id')->get(['id', 'ai_external_allowed'])->map(fn (Workspace $w): array => ['workspace_id' => $w->id, 'ai_external_allowed' => $w->ai_external_allowed])->all());

    $log = AuditLog::query()->where('action', 'ai.settings_updated')->sole();
    expect($log->metadata)->toBe(['reembed_workspaces' => Workspace::query()->count()])
        ->and($log->workspace_id)->toBeNull()
        ->and($log->changes)->toMatchArray(['provider' => ['mock', 'openai'], 'model' => ['mock', 'gpt-5']]);
});

it('validates settings', function (): void {
    fakeAiAdmin();
    platformAdmin($this);

    $this->put(route('admin.ai.settings'), ['provider' => 'Bad Provider!', 'model' => '', 'temperature' => 5, 'embeddings' => ['provider' => 'magic']])
        ->assertSessionHasErrors(['provider', 'model', 'temperature', 'embeddings.provider', 'embeddings.model']);
});

it('stores and clears provider keys without ever logging them', function (): void {
    fakeAiAdmin();
    platformAdmin($this);
    $secret = 'sk-live-very-secret-key-abcd';

    $this->putJson(route('admin.ai.keys', 'openai'), ['api_key' => $secret])
        ->assertOk()
        ->assertJson(['key_hint' => '…abcd']);

    $this->put(route('admin.ai.keys', 'openai'), ['api_key' => null])->assertSessionHas('success', __('notifications.ai.key_cleared'));

    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/v1/admin/ai/keys/openai')
        && $request['api_key'] === $secret);

    $logs = AuditLog::query()->whereIn('action', ['ai.key_updated', 'ai.key_cleared'])->get();
    expect($logs)->toHaveCount(2)
        ->and($logs->every(fn (AuditLog $log): bool => $log->workspace_id === null))->toBeTrue()
        ->and(json_encode($logs->toArray()))->not->toContain('very-secret')
        ->and(json_encode($logs->toArray()))->not->toContain('abcd');
});

it('rejects keys containing whitespace', function (): void {
    fakeAiAdmin();
    platformAdmin($this);

    $this->putJson(route('admin.ai.keys', 'openai'), ['api_key' => 'sk with spaces'])->assertJsonValidationErrors('api_key');
});

it('proxies the live model list and the connection test as JSON', function (): void {
    fakeAiAdmin();
    platformAdmin($this);

    $this->getJson(route('admin.ai.models', 'openai'))->assertOk()->assertJson(['models' => ['gpt-5', 'gpt-5-mini']]);
    $this->postJson(route('admin.ai.test'))->assertOk()->assertJson(['ok' => true, 'latency_ms' => 42]);
});

it('surfaces ai errors to the admin', function (): void {
    Http::fake(['ai.test/*' => Http::response(['detail' => 'Unknown provider'], 404)]);
    platformAdmin($this);

    $this->getJson(route('admin.ai.models', 'nope'))->assertNotFound()->assertJson(['detail' => 'Unknown provider']);
});
