<?php

declare(strict_types=1);

namespace App\Billing\Stripe;

use App\Billing\Markets\MarketResolver;
use App\Billing\PlanCatalog;
use App\Enums\BillingInterval;
use Stripe\Exception\InvalidRequestException;
use Stripe\Price;
use Stripe\Product;
use Stripe\StripeClient;

final readonly class StripeCatalogSync
{
    public function __construct(
        private StripeClient $stripe,
        private PlanCatalog $plans,
        private StripeCatalog $catalog,
        private MarketResolver $markets,
    ) {}

    /** @return list<array{kind: string, key: string, action: string, id: string|null}> */
    public function run(bool $dryRun = false): array
    {
        $report = [];
        $portalProducts = [];

        foreach ($this->plans->paidKeys() as $plan) {
            [$product, $action] = $this->syncProduct($plan, $dryRun);
            $report[] = ['kind' => 'product', 'key' => $this->plans->productId($plan), 'action' => $action, 'id' => $product?->id];
            $priceIds = [];

            foreach ($this->markets->currenciesFor('stripe') as $currency) {
                foreach (BillingInterval::cases() as $interval) {
                    if (! $this->plans->hasPrice($plan, $interval, $currency)) {
                        continue;
                    }

                    [$priceId, $priceAction] = $this->syncPrice($plan, $interval, $currency, $product->id ?? $this->plans->productId($plan), $dryRun);
                    $report[] = ['kind' => 'price', 'key' => $this->plans->lookupKey($plan, $interval, $currency), 'action' => $priceAction, 'id' => $priceId];

                    if ($priceId !== null) {
                        $priceIds[] = $priceId;
                    }
                }
            }

            if ($product !== null && $priceIds !== []) {
                $portalProducts[] = ['product' => $product->id, 'prices' => $priceIds];
            }
        }

        [$configurationId, $portalAction] = $this->syncPortal($portalProducts, $dryRun);
        $report[] = ['kind' => 'portal', 'key' => PlanCatalog::catalogName(), 'action' => $portalAction, 'id' => $configurationId];

        if (! $dryRun) {
            $this->catalog->forget();
        }

        return $report;
    }

    /** @return array{0: Product|null, 1: string} */
    private function syncProduct(string $plan, bool $dryRun): array
    {
        $id = $this->plans->productId($plan);
        $attributes = [
            'name' => trim(config('app.name').' '.__("billing.plans.{$plan}.name", [], 'en')),
            'description' => (string) __("billing.plans.{$plan}.description", [], 'en'),
            'metadata' => ['plan' => $plan, 'catalog' => PlanCatalog::catalogName()],
        ];

        try {
            $existing = $this->stripe->products->retrieve($id);
        } catch (InvalidRequestException $exception) {
            if ($exception->getStripeCode() !== 'resource_missing') {
                throw $exception;
            }

            $existing = null;
        }

        if ($existing === null) {
            if ($dryRun) {
                return [null, 'create'];
            }

            return [$this->stripe->products->create(['id' => $id, ...$attributes], ['idempotency_key' => 'product-'.$id]), 'created'];
        }

        $unchanged = $existing->name === $attributes['name']
            && $existing->description === $attributes['description']
            && $existing->active === true
            && ($existing->metadata['plan'] ?? null) === $plan;

        if ($unchanged) {
            return [$existing, 'unchanged'];
        }

        if ($dryRun) {
            return [$existing, 'update'];
        }

        return [$this->stripe->products->update($existing->id, [...$attributes, 'active' => true]), 'updated'];
    }

    /** @return array{0: string|null, 1: string} */
    private function syncPrice(string $plan, BillingInterval $interval, string $currency, string $productId, bool $dryRun): array
    {
        $lookupKey = $this->plans->lookupKey($plan, $interval, $currency);
        $amount = $this->plans->amount($plan, $interval, $currency);
        $existing = $this->stripe->prices->all(['lookup_keys' => [$lookupKey], 'limit' => 1])->data[0] ?? null;

        if ($existing instanceof Price && $this->matches($existing, $lookupKey, $productId, $amount, $currency, $interval)) {
            return [$existing->id, 'unchanged'];
        }

        if ($dryRun) {
            return [$existing?->id, $existing === null ? 'create' : 'replace'];
        }

        $price = $this->stripe->prices->create([
            'product' => $productId,
            'currency' => strtolower($currency),
            'unit_amount' => $amount,
            'recurring' => ['interval' => $interval->value],
            'lookup_key' => $lookupKey,
            'transfer_lookup_key' => true,
            'tax_behavior' => 'exclusive',
            'metadata' => ['plan' => $plan, 'interval' => $interval->value, 'catalog' => PlanCatalog::catalogName()],
        ], ['idempotency_key' => 'price-'.$lookupKey.'-'.$amount]);

        if ($existing instanceof Price && $existing->id !== $price->id && $existing->active === true) {
            $this->stripe->prices->update($existing->id, ['active' => false]);
        }

        return [$price->id, $existing === null ? 'created' : 'replaced'];
    }

    private function matches(Price $price, string $lookupKey, string $productId, int $amount, string $currency, BillingInterval $interval): bool
    {
        $product = is_string($price->product) ? $price->product : ($price->product->id ?? null);

        return $price->lookup_key === $lookupKey
            && $price->active === true
            && $product === $productId
            && $price->unit_amount === $amount
            && strtoupper((string) $price->currency) === $currency
            && ($price->recurring->interval ?? null) === $interval->value;
    }

    /**
     * @param  list<array{product: string, prices: list<string>}>  $products
     * @return array{0: string|null, 1: string}
     */
    private function syncPortal(array $products, bool $dryRun): array
    {
        $existing = $this->catalog->portalConfigurationId();

        if ($dryRun) {
            return [$existing, $existing === null ? 'create' : 'update'];
        }

        $params = [
            'business_profile' => array_filter([
                'headline' => (string) config('app.name'),
                'privacy_policy_url' => self::publicUrl(route('legal', ['doc' => 'privacy'])),
                'terms_of_service_url' => self::publicUrl(route('legal', ['doc' => 'terms'])),
            ]),
            'default_return_url' => route('settings.billing'),
            'features' => [
                'customer_update' => ['enabled' => true, 'allowed_updates' => ['email', 'address', 'name', 'tax_id']],
                'invoice_history' => ['enabled' => true],
                'payment_method_update' => ['enabled' => true],
                'subscription_cancel' => [
                    'enabled' => true,
                    'mode' => 'at_period_end',
                    'proration_behavior' => 'none',
                    'cancellation_reason' => [
                        'enabled' => true,
                        'options' => ['too_expensive', 'missing_features', 'switched_service', 'unused', 'other'],
                    ],
                ],
                'subscription_update' => [
                    'enabled' => $products !== [],
                    'default_allowed_updates' => ['price', 'promotion_code'],
                    'proration_behavior' => 'create_prorations',
                    'products' => $products,
                ],
            ],
            'metadata' => [StripeCatalog::PORTAL_METADATA_KEY => PlanCatalog::catalogName()],
        ];

        if ($products === []) {
            unset($params['features']['subscription_update']['products'], $params['features']['subscription_update']['default_allowed_updates'], $params['features']['subscription_update']['proration_behavior']);
        }

        if ($existing === null) {
            $configuration = $this->stripe->billingPortal->configurations->create($params, ['idempotency_key' => 'portal-'.PlanCatalog::catalogName().'-'.md5((string) json_encode($params))]);

            return [$configuration->id, 'created'];
        }

        $configuration = $this->stripe->billingPortal->configurations->update($existing, $params, ['idempotency_key' => 'portal-update-'.$existing.'-'.md5((string) json_encode($params))]);

        return [$configuration->id, 'updated'];
    }

    private static function publicUrl(string $url): ?string
    {
        return str_starts_with($url, 'https://') ? $url : null;
    }
}
