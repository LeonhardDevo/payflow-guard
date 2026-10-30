<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\DevServerVite;
use Illuminate\Foundation\Vite as FoundationVite;
use Illuminate\Support\Facades\Vite;

it('sends a strict CSP with a per-request script nonce', function (): void {
    $response = $this->get(route('home'))->assertOk();
    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]+'/")
        ->not->toContain('https://')
        ->not->toContain('*')
        ->and($csp)->toContain("'nonce-".Vite::cspNonce()."'");
});

it('adds the hardening headers', function (): void {
    $this->get(route('home'))
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeaderMissing('Strict-Transport-Security');

    expect($this->get(route('home'))->headers->get('Permissions-Policy'))->toContain('camera=()');
});

it('allows the Vite dev server only in local', function (): void {
    expect($this->get(route('home'))->headers->get('Content-Security-Policy'))->not->toContain('localhost:5173');

    $this->app['env'] = 'local';

    $csp = (string) $this->get(route('home'))->headers->get('Content-Security-Policy');
    expect($csp)->toContain('http://localhost:5173')->toContain('ws://localhost:5173');
});

it('maps an IPv6-loopback dev origin to localhost in the CSP and in asset URLs', function (): void {
    $this->withVite();
    $this->app['env'] = 'local';
    config(['security.csp.vite_dev_server' => '']);
    $hot = public_path('hot');
    $backup = is_file($hot) ? (string) file_get_contents($hot) : null;
    file_put_contents($hot, "http://[::1]:5174\n");

    try {
        $csp = (string) $this->get(route('home'))->headers->get('Content-Security-Policy');
        $vite = app(FoundationVite::class);
        $assetUrl = $vite->asset('resources/js/app.ts');
    } finally {
        $backup === null ? @unlink($hot) : file_put_contents($hot, $backup);
    }

    expect($vite)->toBeInstanceOf(DevServerVite::class)
        ->and($csp)->not->toContain('[::1]')
        ->toContain('http://localhost:5174')
        ->toContain('ws://localhost:5174')
        ->and($assetUrl)->toBe('http://localhost:5174/resources/js/app.ts');
});

it('normalizes only loopback IPv6 dev origins', function (string $origin, string $expected): void {
    expect(DevServerVite::normalizeOrigin($origin))->toBe($expected);
})->with([
    ['http://[::1]:5173', 'http://localhost:5173'],
    ['https://[::1]:5173/', 'https://localhost:5173'],
    ['http://[0:0:0:0:0:0:0:1]:5173', 'http://localhost:5173'],
    ['http://127.0.0.1:5173', 'http://127.0.0.1:5173'],
    ['http://[fe80::1]:5173', 'http://[fe80::1]:5173'],
    ['not a url', 'not a url'],
]);

it('skips a non-loopback IPv6 dev origin instead of emitting an invalid CSP source', function (): void {
    $this->app['env'] = 'local';
    config(['security.csp.vite_dev_server' => 'http://[fe80::1]:5173']);

    $csp = (string) $this->get(route('home'))->headers->get('Content-Security-Policy');

    expect($csp)->not->toContain('fe80');
});

it('sends HSTS in production', function (): void {
    $this->app['env'] = 'production';

    expect($this->get(route('home'))->headers->get('Strict-Transport-Security'))->toContain('max-age=63072000');
});

it('assigns or propagates the request id', function (): void {
    expect($this->get(route('home'))->headers->get('X-Request-Id'))->toMatch('/^[0-9a-f-]{36}$/');

    $this->withHeader('X-Request-Id', 'trace-abc-12345')->get(route('home'))->assertHeader('X-Request-Id', 'trace-abc-12345');

    expect($this->withHeader('X-Request-Id', "bad\nid")->get(route('home'))->headers->get('X-Request-Id'))->not->toContain('bad');
});

it('exposes the /up health check backed by the database', function (): void {
    $this->get('/up')->assertOk();
});

it('throttles logins', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 5) as $_) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'nope']);
    }

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'nope'])->assertTooManyRequests();
});

it('never widens the production CSP for a stale public/hot file', function (): void {
    $this->withVite();
    $this->app['env'] = 'production';
    config(['security.csp.vite_dev_server' => 'http://localhost:5173']);
    $hot = public_path('hot');
    $backup = is_file($hot) ? (string) file_get_contents($hot) : null;
    file_put_contents($hot, "http://localhost:5173\n");

    try {
        $csp = (string) $this->get(route('home'))->headers->get('Content-Security-Policy');
    } finally {
        $backup === null ? @unlink($hot) : file_put_contents($hot, $backup);
    }

    expect($csp)->not->toContain('localhost:5173')->not->toContain('ws://');
});
