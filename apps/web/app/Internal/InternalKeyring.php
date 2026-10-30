<?php

declare(strict_types=1);

namespace App\Internal;

use Illuminate\Support\Facades\Log;

final readonly class InternalKeyring
{
    public const int MIN_PRODUCTION_LENGTH = 32;

    /** @param  array<string, string|null>  $keys */
    public function __construct(
        private array $keys,
        private ?string $sharedSecret,
        private string $devFallbackSecret,
        private bool $production,
    ) {}

    /** @return list<string> */
    public function peers(): array
    {
        return array_keys($this->keys);
    }

    public function secretFor(string $peer): ?string
    {
        if (! array_key_exists($peer, $this->keys)) {
            return null;
        }

        $key = $this->keys[$peer];

        if (is_string($key) && $key !== '') {
            return $key;
        }

        if ($this->production) {
            throw new \RuntimeException('Internal pair key for ['.$peer.'] must be set in production (ADR-0010).');
        }

        return $this->fallbackSecret();
    }

    public function fallbackSecret(): string
    {
        if (is_string($this->sharedSecret) && $this->sharedSecret !== '') {
            return $this->sharedSecret;
        }

        if ($this->production) {
            throw new \RuntimeException('INTERNAL_API_SECRET must be set in production.');
        }

        Log::warning('INTERNAL_API_SECRET is empty; using the built-in development secret (run `npm run setup`).');

        return $this->devFallbackSecret;
    }

    /** @return list<string> */
    public function productionProblems(): array
    {
        $problems = [];
        $seen = [];

        foreach ($this->keys as $peer => $key) {
            if (! is_string($key) || strlen($key) < self::MIN_PRODUCTION_LENGTH) {
                $problems[] = "pair key for [{$peer}] must be at least ".self::MIN_PRODUCTION_LENGTH.' characters';

                continue;
            }

            if (in_array($key, $seen, true)) {
                $problems[] = "pair key for [{$peer}] must differ from the other pair keys";
            }

            $seen[] = $key;
        }

        return $problems;
    }
}
