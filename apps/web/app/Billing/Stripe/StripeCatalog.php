<?php

declare(strict_types=1);

namespace App\Billing\Stripe;

use App\Billing\BillingException;
use App\Billing\PlanCatalog;
use App\Enums\BillingInterval;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

final readonly class StripeCatalog
{
    public const string PORTAL_METADATA_KEY = 'catalog';

    public function __construct(
        private StripeClient $stripe,
        private PlanCatalog $plans,
        private Cache $cache,
    ) {}

    public function priceId(string $plan, BillingInterval $interval, string $currency): ?string
    {
        if (! $this->plans->hasPrice($plan, $interval, $currency)) {
            return null;
        }

        $lookupKey = $this->plans->lookupKey($plan, $interval, $currency);
        $cacheKey = 'billing:stripe:price:'.$lookupKey;
        $cached = $this->cache->get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $prices = $this->stripe->prices->all(['lookup_keys' => [$lookupKey], 'active' => true, 'limit' => 1]);
        } catch (ApiErrorException $exception) {
            Log::error('billing.stripe.price_lookup_failed', ['lookup_key' => $lookupKey, 'error' => $exception->getMessage()]);

            throw new BillingException('provider_unavailable', $exception);
        }

        $price = $prices->data[0] ?? null;

        if ($price === null) {
            return null;
        }

        $this->cache->put($cacheKey, $price->id, (int) config('billing.stripe.price_cache_seconds', 600));

        return $price->id;
    }

    public function portalConfigurationId(): ?string
    {
        $cacheKey = 'billing:stripe:portal:'.PlanCatalog::catalogName();
        $cached = $this->cache->get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $configurations = $this->stripe->billingPortal->configurations->all(['active' => true, 'limit' => 100]);
        } catch (ApiErrorException $exception) {
            Log::warning('billing.stripe.portal_lookup_failed', ['error' => $exception->getMessage()]);

            return null;
        }

        foreach ($configurations->data as $configuration) {
            if (($configuration->metadata[self::PORTAL_METADATA_KEY] ?? null) === PlanCatalog::catalogName()) {
                $this->cache->put($cacheKey, $configuration->id, (int) config('billing.stripe.price_cache_seconds', 600));

                return $configuration->id;
            }
        }

        return null;
    }

    public function forget(): void
    {
        foreach ($this->plans->keys() as $plan) {
            foreach (BillingInterval::cases() as $interval) {
                foreach ($this->plans->currencies() as $currency) {
                    $this->cache->forget('billing:stripe:price:'.$this->plans->lookupKey($plan, $interval, $currency));
                }
            }
        }

        $this->cache->forget('billing:stripe:portal:'.PlanCatalog::catalogName());
    }
}
