<?php

declare(strict_types=1);

use App\Internal\InternalKeyring;
use App\Internal\InternalSignature;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

function usePairKeys(): void
{
    config(['internal.keys' => ['worker' => str_repeat('w', 40), 'ai' => str_repeat('a', 40)]]);
    app()->forgetInstance(InternalKeyring::class);
}

/** @return array<string, string> */
function signedAs(string $service, string $secret, string $method, string $uri, string $body = ''): array
{
    return [
        'X-Service' => $service,
        'X-Signature' => (new InternalSignature($secret))->sign($method, $uri, $body),
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    ];
}

it('forbids the ai service from worker-only endpoints', function (string $method, string $uri): void {
    $this->internal($method, $uri, $method === 'POST' ? ['x' => 1] : null, service: 'ai')
        ->assertForbidden()
        ->assertExactJson(['error' => 'caller_not_allowed']);
})->with([
    'stripe events' => ['POST', '/internal/v1/stripe/events'],
    'deliveries' => ['POST', '/internal/v1/deliveries'],
    'telegram actions' => ['POST', '/internal/v1/telegram/actions'],
    'telegram link' => ['POST', '/internal/v1/telegram/link'],
    'telegram context' => ['GET', '/internal/v1/telegram/context?telegram_user_id=1'],
]);

it('forbids the worker from downloading document files', function (): void {
    $this->internal('GET', '/internal/v1/documents/1/file')
        ->assertForbidden()
        ->assertExactJson(['error' => 'caller_not_allowed']);
});

it('lets both services reach ping', function (string $service): void {
    $this->internal('GET', '/internal/v1/ping', service: $service)->assertOk();
})->with(['worker', 'ai']);

it('verifies each caller with its own pair key', function (): void {
    usePairKeys();

    $this->withHeaders(signedAs('worker', str_repeat('w', 40), 'GET', '/internal/v1/ping'))
        ->get('/internal/v1/ping')
        ->assertOk()
        ->assertJsonPath('caller', 'worker');
    $this->withHeaders(signedAs('ai', str_repeat('a', 40), 'GET', '/internal/v1/ping'))
        ->get('/internal/v1/ping')
        ->assertOk()
        ->assertJsonPath('caller', 'ai');
});

it('rejects an ai key presented as the worker', function (): void {
    usePairKeys();
    $body = '{"id":"evt_1"}';

    $this->withHeaders(signedAs('worker', str_repeat('a', 40), 'POST', '/internal/v1/stripe/events', $body))
        ->call('POST', '/internal/v1/stripe/events', [], [], [], [], $body)
        ->assertUnauthorized()
        ->assertJson(['error' => 'invalid_signature']);
});

it('rejects the legacy shared secret once pair keys are configured', function (): void {
    usePairKeys();

    $this->withHeaders(signedAs('worker', 'test-secret', 'GET', '/internal/v1/ping'))
        ->get('/internal/v1/ping')
        ->assertUnauthorized();
});

it('signs outgoing calls with the pair key of the target', function (): void {
    usePairKeys();
    config(['internal.services.ai.url' => 'http://ai.test']);
    Http::fake(['ai.test/*' => Http::response(['ok' => true])]);

    Http::internal('ai')->get('/v1/ping');

    Http::assertSent(fn (HttpRequest $request): bool => (new InternalSignature(str_repeat('a', 40)))
        ->verify($request->header('X-Signature')[0], 'GET', '/v1/ping', $request->body()));
});

it('requires distinct 32+ character pair keys in production', function (): void {
    $keyring = new InternalKeyring(['worker' => 'short', 'ai' => null], 'shared', 'dev', production: true);
    expect($keyring->productionProblems())->toHaveCount(2);

    $same = new InternalKeyring(['worker' => str_repeat('k', 32), 'ai' => str_repeat('k', 32)], null, 'dev', production: true);
    expect($same->productionProblems())->toBe(['pair key for [ai] must differ from the other pair keys']);

    $ok = new InternalKeyring(['worker' => str_repeat('w', 32), 'ai' => str_repeat('a', 32)], null, 'dev', production: true);
    expect($ok->productionProblems())->toBe([])
        ->and($ok->secretFor('worker'))->toBe(str_repeat('w', 32))
        ->and($ok->secretFor('evil'))->toBeNull();

    expect(fn () => (new InternalKeyring(['worker' => null], 'shared', 'dev', production: true))->secretFor('worker'))
        ->toThrow(RuntimeException::class);
});

it('falls back to the shared secret outside production', function (): void {
    $keyring = new InternalKeyring(['worker' => null], 'shared-secret', 'dev', production: false);

    expect($keyring->secretFor('worker'))->toBe('shared-secret');
    expect((new InternalKeyring(['worker' => null], null, 'dev-fallback', production: false))->secretFor('worker'))->toBe('dev-fallback');
});
