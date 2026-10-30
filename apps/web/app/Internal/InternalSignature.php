<?php

declare(strict_types=1);

namespace App\Internal;

final readonly class InternalSignature
{
    public const string HEADER = 'X-Signature';

    public const string SERVICE_HEADER = 'X-Service';

    public function __construct(
        private string $secret,
        private int $toleranceSeconds = 300,
    ) {
        if ($this->secret === '') {
            throw new \InvalidArgumentException('INTERNAL_API_SECRET is not configured.');
        }
    }

    public static function compute(string $secret, int $timestamp, string $method, string $pathWithQuery, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.strtoupper($method).'.'.$pathWithQuery.'.'.$body, $secret);
    }

    public function sign(string $method, string $pathWithQuery, string $body, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.',v1='.self::compute($this->secret, $timestamp, $method, $pathWithQuery, $body);
    }

    public function verify(?string $header, string $method, string $pathWithQuery, string $body, ?int $now = null): bool
    {
        $parsed = self::parse($header);

        if ($parsed === null) {
            return false;
        }

        $now ??= time();

        if (abs($now - $parsed['t']) > $this->toleranceSeconds) {
            return false;
        }

        $expected = self::compute($this->secret, $parsed['t'], $method, $pathWithQuery, $body);

        foreach ($parsed['v1'] as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{t: int, v1: list<string>}|null */
    public static function parse(?string $header): ?array
    {
        if ($header === null || $header === '') {
            return null;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && preg_match('/^[a-f0-9]{64}$/', $value) === 1) {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return null;
        }

        return ['t' => $timestamp, 'v1' => $signatures];
    }
}
