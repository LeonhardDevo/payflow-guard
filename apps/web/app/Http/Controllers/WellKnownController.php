<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

final class WellKnownController extends Controller
{
    public function securityTxt(): Response
    {
        $lines = [
            'Contact: '.config('legal.security.contact'),
            'Expires: '.now()->utc()->addDays(max(1, min(364, (int) config('legal.security.expires_days', 180))))->startOfDay()->format('Y-m-d\TH:i:s\Z'),
            'Preferred-Languages: '.config('legal.security.preferred_languages'),
            'Canonical: '.url('/.well-known/security.txt'),
            'Policy: '.route('security'),
        ];

        if (filled(config('legal.security.encryption'))) {
            $lines[] = 'Encryption: '.config('legal.security.encryption');
        }

        return $this->text(implode("\n", $lines)."\n");
    }

    public function robots(): Response
    {
        $private = [
            '/dashboard', '/assistant', '/knowledge', '/audit', '/settings', '/admin', '/onboarding',
            '/invitations', '/email/', '/internal/', '/dev/', '/legal/accept', '/login', '/register',
            '/forgot-password', '/reset-password', '/two-factor-challenge',
        ];

        $lines = ['User-agent: *', ...array_map(fn (string $path): string => 'Disallow: '.$path, $private), 'Allow: /'];

        return $this->text(implode("\n", $lines)."\n");
    }

    private function text(string $body): Response
    {
        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
