<?php

declare(strict_types=1);

use App\Internal\InternalSignature;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

it('answers a correctly signed ping', function (): void {
    $this->internal('GET', '/internal/v1/ping', service: 'ai')
        ->assertOk()
        ->assertJson(['ok' => true, 'service' => 'web', 'caller' => 'ai']);
});

it('accepts a signed POST body and echoes the request id', function (): void {
    $response = $this->internal('POST', '/internal/v1/deliveries', [
        'reference' => ['type' => 'mail', 'id' => 'abc'],
        'channel' => 'email',
        'status' => 'sent',
    ]);

    $response->assertCreated();
    expect($response->headers->get('X-Request-Id'))->toStartWith('test-request-');
});

it('rejects requests without a signature', function (): void {
    $this->getJson('/internal/v1/ping', ['X-Service' => 'worker'])
        ->assertUnauthorized()
        ->assertJson(['error' => 'invalid_signature']);
});

it('rejects an unknown calling service', function (): void {
    $this->internal('GET', '/internal/v1/ping', service: 'web')->assertUnauthorized();
    $this->internal('GET', '/internal/v1/ping', service: 'evil')->assertUnauthorized();
});

it('rejects stale and future timestamps', function (): void {
    $this->internal('GET', '/internal/v1/ping', timestamp: time() - 301)->assertUnauthorized();
    $this->internal('GET', '/internal/v1/ping', timestamp: time() + 301)->assertUnauthorized();
    $this->internal('GET', '/internal/v1/ping', timestamp: time() - 290)->assertOk();
});

it('rejects a signature computed over a different body', function (): void {
    $signature = app(InternalSignature::class)->sign('POST', '/internal/v1/deliveries', '{"a":1}');
    $server = $this->transformHeadersToServerVars([
        'X-Service' => 'worker',
        'X-Signature' => $signature,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ]);

    $this->call('POST', '/internal/v1/deliveries', [], [], [], $server, '{"a":2}')->assertUnauthorized();
});

it('signs the path including the query string', function (): void {
    $signature = app(InternalSignature::class)->sign('GET', '/internal/v1/telegram/context', '');
    $server = $this->transformHeadersToServerVars([
        'X-Service' => 'worker',
        'X-Signature' => $signature,
        'Accept' => 'application/json',
    ]);

    $this->call('GET', '/internal/v1/telegram/context?telegram_user_id=1', [], [], [], $server)->assertUnauthorized();
});

it('needs no session or CSRF token', function (): void {
    $response = $this->internal('POST', '/internal/v1/deliveries', [
        'reference' => ['type' => 'mail', 'id' => 'x'],
        'channel' => 'telegram',
        'status' => 'failed',
        'error' => 'chat not found',
    ]);

    $response->assertCreated();
    expect($response->headers->getCookies())->toBeEmpty();
});

it('returns JSON 422 for invalid internal payloads', function (): void {
    $this->internal('POST', '/internal/v1/deliveries', ['channel' => 'pigeon'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'validation_failed')
        ->assertJsonStructure(['errors' => ['reference', 'channel', 'status']]);
});

it('signs outgoing calls through the Http::internal macro', function (): void {
    Http::fake(['worker.test/*' => Http::response(['ok' => true])]);

    $this->withHeader('X-Request-Id', 'outer-request-42')->get('/up');
    Http::internal('worker')->post('/hooks/test?x=1', ['a' => 1]);

    Http::assertSent(function (HttpRequest $request): bool {
        $signature = app(InternalSignature::class);

        return $request->url() === 'http://worker.test/hooks/test?x=1'
            && $request->header('X-Service') === ['web']
            && $request->header('X-Request-Id') === ['outer-request-42']
            && $signature->verify($request->header('X-Signature')[0], 'POST', '/hooks/test?x=1', $request->body());
    });
});

it('rate limits each calling service', function (): void {
    config(['internal.rate_limit' => 3]);

    foreach (range(1, 3) as $_) {
        $this->internal('GET', '/internal/v1/ping')->assertOk();
    }

    $this->internal('GET', '/internal/v1/ping')->assertTooManyRequests();
    $this->internal('GET', '/internal/v1/ping', service: 'ai')->assertOk();
});
