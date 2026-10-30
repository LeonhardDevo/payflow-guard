<?php

declare(strict_types=1);

namespace App\Billing\Fake;

use Illuminate\Contracts\Cache\Repository as Cache;

final readonly class FakeCheckoutStore
{
    private const string PREFIX = 'billing:fake-checkout:';

    public function __construct(private Cache $cache) {}

    public function put(FakeCheckoutSession $session): void
    {
        $this->cache->put(
            self::PREFIX.$session->id,
            $session->toArray(),
            now()->addMinutes((int) config('billing.fake.session_ttl_minutes', 60)),
        );
    }

    public function find(string $id): ?FakeCheckoutSession
    {
        $data = $this->cache->get(self::PREFIX.$id);

        return is_array($data) ? FakeCheckoutSession::fromArray($data) : null;
    }

    public function forget(string $id): void
    {
        $this->cache->forget(self::PREFIX.$id);
    }
}
