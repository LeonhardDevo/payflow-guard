<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspaces;

use App\Billing\BillingGateways;
use App\Billing\Markets\MarketResolver;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Validation\Validator;

final class UpdateWorkspaceRequest extends WorkspaceRequest
{
    public function authorize(): bool
    {
        $workspace = app(CurrentWorkspace::class)->get();

        return $workspace !== null && ($this->user()?->can('update', $workspace) ?? false);
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $workspace = app(CurrentWorkspace::class)->get();
                $subscription = $workspace?->subscription;

                if ($workspace === null || $subscription === null || ! $subscription->grantsPlan()) {
                    return;
                }

                $country = $this->filled('country') ? (string) $this->input('country') : $workspace->country;
                $currency = (string) $this->input('currency', $workspace->currency);
                $target = app(MarketResolver::class)->forCountry($country, $currency)->gateway;
                $current = app(BillingGateways::class)->driverNameForSubscription($workspace, $subscription);

                if ($target !== $current) {
                    $validator->errors()->add(
                        $this->filled('country') && $country !== $workspace->country ? 'country' : 'currency',
                        (string) __('billing.errors.market_locked_by_subscription', [
                            'gateway' => __("billing.gateways.{$current}"),
                        ]),
                    );
                }
            },
        ];
    }
}
