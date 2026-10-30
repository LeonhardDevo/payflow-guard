<?php

declare(strict_types=1);

namespace App\Billing;

use App\Billing\Markets\MarketResolver;
use App\Models\Subscription;
use App\Models\Workspace;
use Illuminate\Contracts\Container\Container;

final readonly class BillingGateways
{
    public function __construct(
        private Container $container,
        private MarketResolver $markets,
    ) {}

    public function forWorkspace(Workspace $workspace): BillingGateway
    {
        return $this->driver($this->driverNameFor($workspace));
    }

    public function forSubscription(Workspace $workspace, Subscription $subscription): BillingGateway
    {
        return $this->driver($this->driverNameForSubscription($workspace, $subscription));
    }

    public function driverNameFor(Workspace $workspace): string
    {
        return $this->markets->forWorkspace($workspace)->gateway;
    }

    public function driverNameForSubscription(Workspace $workspace, ?Subscription $subscription): string
    {
        $forced = $this->markets->forcedGateway();

        if ($forced !== null) {
            return $forced;
        }

        if ($subscription !== null && $subscription->grantsPlan() && $subscription->gateway !== '') {
            return $subscription->gateway;
        }

        return $this->driverNameFor($workspace);
    }

    public function default(): BillingGateway
    {
        return $this->driver($this->markets->forcedGateway() ?? (string) config('billing.markets.default_gateway', 'stripe'));
    }

    public function available(string $name): bool
    {
        return $this->markets->gatewayAvailable($name);
    }

    public function driver(string $name): BillingGateway
    {
        if (! $this->available($name)) {
            throw new BillingException('gateway_unavailable');
        }

        /** @var class-string<BillingGateway> $class */
        $class = config("billing.gateways.{$name}");

        return $this->container->make($class);
    }
}
