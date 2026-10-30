<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Foundation\Vite;

class DevServerVite extends Vite
{
    private const LOOPBACK_V6 = ['[::1]', '[0:0:0:0:0:0:0:1]', '[::ffff:127.0.0.1]', '[::ffff:7f00:1]'];

    public function devServerUrl()
    {
        $url = parent::devServerUrl();

        return $url === null ? null : self::normalizeOrigin($url);
    }

    public static function normalizeOrigin(string $origin): string
    {
        $origin = rtrim(trim($origin), '/');
        $parts = parse_url($origin);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $origin;
        }

        $host = strtolower($parts['host']);

        if (! in_array($host, self::LOOPBACK_V6, true)) {
            return $origin;
        }

        return $parts['scheme'].'://localhost'
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '');
    }
}
