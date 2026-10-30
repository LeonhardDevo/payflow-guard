<?php

declare(strict_types=1);

namespace App\Legal;

use App\Ai\AiClient;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

final readonly class AiProviderDisclosure
{
    public const string CACHE_KEY = 'legal:ai-providers';

    private const array ON_DEVICE = ['mock', 'ollama'];

    public function __construct(
        private AiClient $ai,
        private Cache $cache,
        private Config $config,
    ) {}

    /** @return list<string> */
    public function enabled(): array
    {
        $declared = array_values(array_filter((array) $this->config->get('legal.llm_providers', []), is_string(...)));

        return array_values(array_unique([...$declared, ...($this->discovered() ?? [])]));
    }

    /** @return list<string>|null */
    public function discovered(): ?array
    {
        if (! (bool) $this->config->get('legal.ai_discovery', true)) {
            return null;
        }

        /** @var list<string>|null $cached */
        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $providers = $this->ai->providers();
        } catch (Throwable) {
            return null;
        }

        $ids = [];

        foreach ($providers as $provider) {
            $id = $provider['id'] ?? null;

            if (is_string($id) && ($provider['configured'] ?? false) === true && ! in_array($id, self::ON_DEVICE, true)) {
                $ids[] = $id;
            }
        }

        $this->cache->put(self::CACHE_KEY, $ids, 600);

        return $ids;
    }

    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }
}
