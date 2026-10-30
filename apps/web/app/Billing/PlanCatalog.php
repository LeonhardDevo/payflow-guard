<?php

declare(strict_types=1);

namespace App\Billing;

use App\Enums\BillingInterval;

final class PlanCatalog
{
    /** @return list<string> */
    public function keys(): array
    {
        /** @var array<string, mixed> $plans */
        $plans = config('billing.plans', []);

        return array_keys($plans);
    }

    /** @return list<string> */
    public function paidKeys(): array
    {
        return array_values(array_filter(
            $this->keys(),
            fn (string $key): bool => $this->amount($key, BillingInterval::Month, $this->currency()) > 0,
        ));
    }

    public function exists(string $key): bool
    {
        return in_array($key, $this->keys(), true);
    }

    public function isPaid(string $key): bool
    {
        return in_array($key, $this->paidKeys(), true);
    }

    /** @return array<string, int|null> */
    public function limits(string $key): array
    {
        /** @var array<string, int|null> $limits */
        $limits = config("billing.plans.{$key}.limits", []);

        return $limits;
    }

    /** @return list<string> */
    public function entitlements(string $key): array
    {
        $entitlements = config("billing.plans.{$key}.entitlements", []);

        return is_array($entitlements) ? array_values(array_filter($entitlements, is_string(...))) : [];
    }

    public function limit(string $key, string $limit): ?int
    {
        $limits = $this->limits($key);

        return array_key_exists($limit, $limits) ? $limits[$limit] : null;
    }

    public function amount(string $key, BillingInterval $interval, string $currency): int
    {
        return (int) config("billing.plans.{$key}.prices.".strtoupper($currency).".{$interval->value}", 0);
    }

    public function hasPrice(string $key, BillingInterval $interval, string $currency): bool
    {
        return is_int(config("billing.plans.{$key}.prices.".strtoupper($currency).".{$interval->value}"));
    }

    /** @return list<string> */
    public function currencies(): array
    {
        $currencies = [];

        foreach ($this->keys() as $key) {
            /** @var array<string, mixed> $prices */
            $prices = config("billing.plans.{$key}.prices", []);
            $currencies = [...$currencies, ...array_keys($prices)];
        }

        return array_values(array_unique(array_map(strtoupper(...), $currencies)));
    }

    public function currency(): string
    {
        $currency = strtoupper((string) config('billing.currency', 'USD'));

        return in_array($currency, $this->currencies(), true) ? $currency : 'USD';
    }

    public function lookupKey(string $key, BillingInterval $interval, string $currency): string
    {
        return strtolower(self::catalogName().'_'.$key.'_'.$interval->value.'_'.$currency);
    }

    public function productId(string $key): string
    {
        return strtolower(self::catalogName().'_'.$key);
    }

    /** @return array{plan: string, interval: BillingInterval, currency: string}|null */
    public function findByLookupKey(string $lookupKey): ?array
    {
        foreach ($this->keys() as $key) {
            foreach (BillingInterval::cases() as $interval) {
                foreach ($this->currencies() as $currency) {
                    if ($this->lookupKey($key, $interval, $currency) === $lookupKey) {
                        return ['plan' => $key, 'interval' => $interval, 'currency' => $currency];
                    }
                }
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function all(?string $currency = null): array
    {
        $currency = strtoupper($currency ?? $this->currency());

        return array_map(fn (string $key): array => $this->toContract($key, $currency), $this->keys());
    }

    /** @return array<string, mixed> */
    public function toContract(string $key, ?string $currency = null): array
    {
        $currency = strtoupper($currency ?? $this->currency());

        return [
            'key' => $key,
            'name' => __("billing.plans.{$key}.name"),
            'description' => __("billing.plans.{$key}.description"),
            'price_monthly' => self::major($this->amount($key, BillingInterval::Month, $currency)),
            'price_yearly' => self::major($this->amount($key, BillingInterval::Year, $currency)),
            'currency' => $currency,
            'features' => $this->features($key),
            'limits' => $this->limits($key),
            'highlighted' => (bool) config("billing.plans.{$key}.highlighted", false),
        ];
    }

    /** @return list<string> */
    public function features(string $key): array
    {
        /** @var list<string> $features */
        $features = config("billing.plans.{$key}.features", []);
        $limits = $this->limits($key);
        $limitKeyForFeature = ['seats' => 'seats', 'documents' => 'documents', 'assistant' => 'ai_queries_monthly'];

        $bullets = [];

        foreach ($features as $feature) {
            $limitKey = $limitKeyForFeature[$feature] ?? null;

            if ($limitKey === null) {
                $bullets[] = (string) __("billing.features.{$feature}");

                continue;
            }

            $value = $limits[$limitKey] ?? null;
            $bullets[] = $value === null
                ? (string) __("billing.features.{$feature}_unlimited")
                : trans_choice("billing.features.{$feature}", $value, ['count' => number_format($value, 0, '.', ' ')]);
        }

        if (isset($limits['storage_mb'])) {
            $bullets[] = (string) __('billing.features.storage', ['size' => self::formatStorage($limits['storage_mb'])]);
        }

        return $bullets;
    }

    public static function major(int $minor): int|float
    {
        return $minor % 100 === 0 ? intdiv($minor, 100) : $minor / 100;
    }

    public static function catalogName(): string
    {
        $name = preg_replace('/[^a-z0-9]+/', '_', strtolower((string) config('billing.stripe.catalog', 'app')));

        return trim((string) $name, '_') ?: 'app';
    }

    public static function formatStorage(int $megabytes): string
    {
        return $megabytes >= 1024
            ? __('billing.units.gb', ['value' => rtrim(rtrim(number_format($megabytes / 1024, 1, '.', ''), '0'), '.')])
            : __('billing.units.mb', ['value' => $megabytes]);
    }
}
