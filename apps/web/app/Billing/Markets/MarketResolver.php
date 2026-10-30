<?php

declare(strict_types=1);

namespace App\Billing\Markets;

use App\Billing\BillingGateway;
use App\Models\Workspace;
use Illuminate\Http\Request;

final class MarketResolver
{
    public const array FORCED_DRIVERS = ['fake', 'stripe', 'yookassa'];

    /** @return list<string> */
    public function countries(): array
    {
        /** @var list<string> $countries */
        $countries = config('billing.markets.countries', []);

        return array_map(strtoupper(...), $countries);
    }

    /** @return list<array{code: string, name: string, currency: string, gateway: string, available: bool}> */
    public function countryOptions(): array
    {
        $options = array_map(function (string $code): array {
            $gateway = $this->gatewayFor($code, null);

            return [
                'code' => $code,
                'name' => self::countryName($code),
                'currency' => $this->currencyFor($code, $gateway, null),
                'gateway' => $gateway,
                'available' => $this->gatewayAvailable($this->forcedGateway() ?? $gateway),
            ];
        }, $this->countries());

        usort($options, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $options;
    }

    public function forCountry(?string $country, ?string $preferredCurrency = null): Market
    {
        $country = $this->normalizeCountry($country);
        $gateway = $this->forcedGateway() ?? $this->gatewayFor($country, $preferredCurrency);

        return new Market($country, $this->currencyFor($country, $gateway, $preferredCurrency), $gateway);
    }

    public function forWorkspace(Workspace $workspace): Market
    {
        return $this->forCountry($workspace->country, $workspace->currency);
    }

    public function forRequest(Request $request, ?Workspace $workspace = null): Market
    {
        $requested = strtoupper((string) $request->query('currency', ''));

        if ($requested !== '' && in_array($requested, $this->billingCurrencies(), true)) {
            return $this->forCurrency($requested);
        }

        if ($workspace !== null) {
            return $this->forWorkspace($workspace);
        }

        /** @var array<string, string> $localeCurrencies */
        $localeCurrencies = config('billing.markets.locale_currencies', []);
        $currency = $localeCurrencies[app()->getLocale()] ?? null;
        $market = $currency !== null ? $this->forCurrency($currency) : $this->forCountry(null);

        return $this->gatewayAvailable($market->gateway) ? $market : $this->forCountry(null);
    }

    public function forCurrency(string $currency): Market
    {
        $currency = strtoupper($currency);
        /** @var array<string, string> $countryCurrencies */
        $countryCurrencies = config('billing.markets.country_currencies', []);
        $default = $this->normalizeCountry(null);
        $country = ($countryCurrencies[$default] ?? null) === $currency || ! in_array($currency, $countryCurrencies, true)
            ? $default
            : (string) array_search($currency, $countryCurrencies, true);

        return $this->forCountry($country, $currency);
    }

    /** @return list<string> */
    public function billingCurrencies(): array
    {
        /** @var array<string, list<string>> $map */
        $map = config('billing.markets.gateway_currencies', []);
        $currencies = [];

        $forced = $this->forcedGateway();

        if ($forced !== null) {
            return $this->gatewayAvailable($forced) ? $this->currenciesFor($forced) : [];
        }

        foreach ($map as $gateway => $list) {
            if ($gateway !== 'fake' && $this->gatewayAvailable((string) $gateway)) {
                $currencies = [...$currencies, ...$list];
            }
        }

        return array_values(array_unique(array_map(strtoupper(...), $currencies)));
    }

    /** @return list<string> */
    public function currenciesFor(string $gateway): array
    {
        /** @var list<string> $currencies */
        $currencies = config("billing.markets.gateway_currencies.{$gateway}", []);

        return array_map(strtoupper(...), $currencies);
    }

    public function gatewayAvailable(string $name): bool
    {
        if ($name === 'fake' && $this->forcedGateway() !== 'fake') {
            return false;
        }

        $class = config("billing.gateways.{$name}");

        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, BillingGateway::class)) {
            return false;
        }

        /** @var list<string> $required */
        $required = config("billing.gateway_requirements.{$name}", []);

        foreach ($required as $key) {
            $value = config($key);

            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }

    public function forcedGateway(): ?string
    {
        $driver = (string) config('billing.driver', 'fake');

        return in_array($driver, self::FORCED_DRIVERS, true) ? $driver : null;
    }

    public static function countryName(string $code): string
    {
        $key = 'billing.countries.'.strtoupper($code);
        $name = __($key);

        return is_string($name) && $name !== $key ? $name : strtoupper($code);
    }

    private function gatewayFor(string $country, ?string $preferredCurrency): string
    {
        /** @var array<string, string> $byCountry */
        $byCountry = config('billing.markets.country_gateways', []);
        /** @var array<string, string> $byCurrency */
        $byCurrency = config('billing.markets.currency_gateways', []);

        return $byCountry[$country]
            ?? $byCurrency[strtoupper((string) $preferredCurrency)]
            ?? (string) config('billing.markets.default_gateway', 'stripe');
    }

    private function currencyFor(string $country, string $gateway, ?string $preferredCurrency): string
    {
        $supported = $this->currenciesFor($gateway);
        $preferred = strtoupper((string) $preferredCurrency);

        if ($preferred !== '' && in_array($preferred, $supported, true)) {
            return $preferred;
        }

        /** @var array<string, string> $countryCurrencies */
        $countryCurrencies = config('billing.markets.country_currencies', []);
        $byCountry = $countryCurrencies[$country] ?? null;

        if ($byCountry !== null && in_array($byCountry, $supported, true)) {
            return $byCountry;
        }

        return $supported[0] ?? 'USD';
    }

    private function normalizeCountry(?string $country): string
    {
        $country = strtoupper(trim((string) $country));

        return preg_match('/^[A-Z]{2}$/', $country) === 1
            ? $country
            : strtoupper((string) config('billing.markets.default_country', 'US'));
    }
}
