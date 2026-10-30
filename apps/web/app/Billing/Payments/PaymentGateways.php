<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\BillingException;
use App\Billing\Markets\MarketResolver;
use App\Models\PaymentAccount;
use App\Models\Workspace;

final readonly class PaymentGateways
{
    public function __construct(private MarketResolver $markets) {}

    public function forAccount(?PaymentAccount $account): PaymentGateway
    {
        $fake = $this->markets->forcedGateway() === 'fake';
        $name = $fake ? 'fake' : $account?->driver;

        if ($name === null || ($name === 'fake' && ! $fake)) {
            throw new BillingException('payment_account_missing');
        }

        $class = config("billing.payment_gateways.{$name}");

        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, PaymentGateway::class)) {
            throw new BillingException('gateway_unavailable');
        }

        return $class::fromAccount($account);
    }

    public function accountFor(Workspace $workspace, string $currency): ?PaymentAccount
    {
        return PaymentAccount::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('connected_at')
            ->get()
            ->first(fn (PaymentAccount $account): bool => in_array(strtoupper($currency), $this->markets->currenciesFor($account->driver), true));
    }
}
