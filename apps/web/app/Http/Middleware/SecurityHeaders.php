<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\DevServerVite;
use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final readonly class SecurityHeaders
{
    public function __construct(private Vite $vite) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = $this->vite->useCspNonce();

        $response = $next($request);
        $headers = $response->headers;

        if ((bool) config('security.csp.enabled', true) && ! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->policy($nonce));
        }

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', (string) config('security.referrer_policy'));
        $headers->set('Permissions-Policy', (string) config('security.permissions_policy'));
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        if (app()->isProduction() || (bool) config('security.hsts.enabled')) {
            $headers->set(
                'Strict-Transport-Security',
                'max-age='.(int) config('security.hsts.max_age', 63072000).'; includeSubDomains; preload',
            );
        }

        return $response;
    }

    public function policy(string $nonce): string
    {
        $dev = $this->devOrigins();
        $devHttp = implode(' ', $dev['http']);
        $devWs = implode(' ', $dev['ws']);
        $extraConnect = implode(' ', (array) config('security.csp.connect_src', []));

        $directives = [
            'default-src' => "'self'",
            'base-uri' => "'self'",
            'object-src' => "'none'",
            'frame-ancestors' => "'none'",
            'form-action' => "'self'",
            'script-src' => trim("'self' 'nonce-{$nonce}' {$devHttp}"),
            'style-src' => trim("'self' 'unsafe-inline' {$devHttp}"),
            'img-src' => trim("'self' data: blob: {$devHttp}"),
            'font-src' => trim("'self' data: {$devHttp}"),
            'connect-src' => trim("'self' {$devHttp} {$devWs} {$extraConnect}"),
            'media-src' => "'self' blob:",
            'worker-src' => "'self' blob:",
            'manifest-src' => "'self'",
            'frame-src' => "'none'",
        ];

        if (app()->isProduction()) {
            $directives['upgrade-insecure-requests'] = '';
        }

        return implode('; ', array_map(
            fn (string $name, string $value): string => trim($name.' '.$value),
            array_keys($directives),
            $directives,
        ));
    }

    /** @return array{http: list<string>, ws: list<string>} */
    private function devOrigins(): array
    {
        if (app()->isProduction() || (! app()->isLocal() && ! $this->vite->isRunningHot())) {
            return ['http' => [], 'ws' => []];
        }

        $origins = [];
        $configured = config('security.csp.vite_dev_server');

        if (is_string($configured) && $configured !== '') {
            $origins[] = $configured;
        }

        $hotFile = $this->vite->hotFile();

        if (is_file($hotFile)) {
            $origins[] = trim((string) file_get_contents($hotFile));
        }

        $http = [];
        $ws = [];

        foreach ($origins as $origin) {
            $parts = parse_url(DevServerVite::normalizeOrigin($origin));

            if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
                continue;
            }

            if (str_starts_with($parts['host'], '[')) {
                Log::warning('Vite dev server runs on a non-loopback IPv6 literal, which CSP cannot allow; start it with --host 127.0.0.1.', ['origin' => $origin]);

                continue;
            }

            $hostPort = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            $http[] = $parts['scheme'].'://'.$hostPort;
            $ws[] = ($parts['scheme'] === 'https' ? 'wss' : 'ws').'://'.$hostPort;
        }

        return ['http' => array_values(array_unique($http)), 'ws' => array_values(array_unique($ws))];
    }
}
