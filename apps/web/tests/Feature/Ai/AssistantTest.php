<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Internal\InternalSignature;
use App\Models\AiQuery;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

function chatResponse(array $overrides = []): array
{
    return [
        'answer_markdown' => 'Refunds are possible within **14 days** [1].',
        'answer_html' => '<p>Refunds are possible within <strong>14 days</strong> [1].</p>',
        'citations' => [['document_id' => 1, 'title' => 'Refund policy', 'page' => 2, 'snippet' => '14 days']],
        'confidence' => 0.82,
        'refused' => false,
        'tools_used' => ['search_documents'],
        'actions' => [],
        'provider' => 'mock',
        'model' => 'mock',
        'latency_ms' => 12,
        ...$overrides,
    ];
}

it('renders the bot preview with localized suggestions', function (): void {
    [$user] = $this->signInMember(WorkspaceRole::Member);
    $user->forceFill(['locale' => 'ru'])->save();

    $this->get(route('assistant'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('assistant/Index', false)
            ->where('suggestions', config('product.assistant_suggestions.ru')));
});

it('asks the ai service with role, permissions, locale and channel web (signed)', function (): void {
    Http::fake(['ai.test/v1/chat' => Http::response(chatResponse())]);
    [$user, $workspace] = $this->signInMember(WorkspaceRole::Member);

    $this->postJson(route('assistant.ask'), ['message' => 'What is the refund policy?', 'conversation_id' => 'conv_1'])
        ->assertOk()
        ->assertJson(chatResponse());

    Http::assertSent(function (HttpRequest $request) use ($user, $workspace): bool {
        $signature = app(InternalSignature::class);
        $path = parse_url($request->url(), PHP_URL_PATH);

        return $request->url() === 'http://ai.test/v1/chat'
            && $request->method() === 'POST'
            && $request['workspace_id'] === $workspace->id
            && $request['user'] === [
                'id' => $user->id,
                'role' => 'member',
                'locale' => 'en',
                'permissions' => WorkspaceRole::Member->abilities(),
            ]
            && $request['channel'] === 'web'
            && $request['conversation_id'] === 'conv_1'
            && $request['message'] === 'What is the refund policy?'
            && $request->header('X-Service') === ['web']
            && $request->hasHeader('X-Request-Id')
            && $signature->verify($request->header('X-Signature')[0], 'POST', (string) $path, $request->body());
    });
});

it('returns a localized 503 when the ai service is down', function (): void {
    Http::fake(fn () => throw new ConnectionException('refused'));
    [$user] = $this->signInMember(WorkspaceRole::Member);
    $user->forceFill(['locale' => 'ru'])->save();

    $this->postJson(route('assistant.ask'), ['message' => 'Привет'])
        ->assertStatus(503)
        ->assertJson(['message' => __('notifications.assistant.unavailable', [], 'ru')]);
});

it('maps ai errors to 502', function (): void {
    Http::fake(['ai.test/*' => Http::response(['detail' => 'boom'], 500)]);
    $this->signInMember(WorkspaceRole::Member);

    $this->postJson(route('assistant.ask'), ['message' => 'Hello there'])->assertStatus(502);
});

it('validates the question', function (): void {
    $this->signInMember(WorkspaceRole::Member);

    $this->postJson(route('assistant.ask'), ['message' => ''])->assertUnprocessable()->assertJsonValidationErrors('message');
    $this->postJson(route('assistant.ask'), ['message' => str_repeat('a', 2001)])->assertJsonValidationErrors('message');
    $this->postJson(route('assistant.ask'), ['message' => 'ok?', 'conversation_id' => 'bad id!'])->assertJsonValidationErrors('conversation_id');
});

it('enforces the monthly AI answers limit with a 402', function (): void {
    Http::fake();
    [, $workspace] = $this->signInMember(WorkspaceRole::Member);
    config(['billing.plans.free.limits.ai_queries_monthly' => 1]);
    AiQuery::query()->create(['workspace_id' => $workspace->id, 'channel' => 'telegram', 'question' => 'q', 'refused' => false]);

    $this->postJson(route('assistant.ask'), ['message' => 'one more?'])->assertStatus(402)->assertJsonPath('limit', 'ai_queries_monthly');
    Http::assertNothingSent();
});

it('logs ai queries idempotently via the internal API', function (): void {
    [$user, $workspace] = $this->member(WorkspaceRole::Member);

    $payload = [
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'channel' => 'web',
        'question' => 'Refund?',
        'answer' => '14 days',
        'refused' => false,
        'confidence' => 0.91,
        'citations' => [['document_id' => 3, 'title' => 'Refunds', 'page' => 1]],
        'tools_used' => ['search_documents'],
        'latency_ms' => 120,
        'provider' => 'mock',
        'model' => 'mock-1',
    ];

    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $headers = fn (): array => $this->transformHeadersToServerVars([
        'X-Service' => 'ai',
        'X-Signature' => app(InternalSignature::class)->sign('POST', '/internal/v1/ai-queries', $body),
        'X-Request-Id' => 'chat-request-0001',
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ]);

    $this->call('POST', '/internal/v1/ai-queries', [], [], [], $headers(), $body)->assertCreated();
    $this->call('POST', '/internal/v1/ai-queries', [], [], [], $headers(), $body)->assertOk()->assertJson(['duplicate' => true]);

    $query = AiQuery::query()->sole();
    expect($query->request_id)->toBe('chat-request-0001')
        ->and($query->confidence)->toBe(0.91)
        ->and($query->citations)->toEqual([['document_id' => 3, 'title' => 'Refunds', 'page' => 1]]);

    $this->internal('POST', '/internal/v1/ai-queries', [...$payload, 'workspace_id' => 999999], 'ai')->assertStatus(422);
});

it('shows the question history with filters and stats', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Admin);
    $asker = User::factory()->create(['name' => 'Asker']);
    $make = fn (array $attributes) => AiQuery::query()->create([
        'workspace_id' => $workspace->id, 'channel' => 'web', 'question' => 'q', 'refused' => false, ...$attributes,
    ]);
    $make(['question' => 'Refund rules?', 'confidence' => 0.9, 'user_id' => $asker->id, 'citations' => [['document_id' => 1, 'title' => 'R', 'page' => null]]]);
    $make(['question' => 'Travel per diem?', 'refused' => true, 'confidence' => 0.1, 'channel' => 'telegram']);
    AiQuery::query()->create(['workspace_id' => Workspace::factory()->create()->id, 'channel' => 'web', 'question' => 'foreign', 'refused' => false]);

    $this->get(route('assistant.history'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('assistant/History', false)
            ->has('queries.data', 2)
            ->where('stats', ['total' => 2, 'answered' => 1, 'refused' => 1, 'avg_confidence' => 0.5])
            ->where('queries.data.1.user', ['id' => $asker->id, 'name' => 'Asker'])
            ->where('queries.data.1.citations', [['document_id' => 1, 'title' => 'R', 'page' => null]]));

    $this->get(route('assistant.history', ['status' => 'refused', 'channel' => 'telegram']))
        ->assertInertia(fn ($page) => $page
            ->has('queries.data', 1)
            ->where('queries.data.0.question', 'Travel per diem?')
            ->where('filters', ['status' => 'refused', 'channel' => 'telegram']));

    $this->get(route('assistant.history', ['q' => 'refund']))
        ->assertInertia(fn ($page) => $page->has('queries.data', 1));
});
