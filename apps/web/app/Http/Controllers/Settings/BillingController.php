<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Billing\BillingException;
use App\Billing\BillingGateways;
use App\Billing\ManagesSubscriptions;
use App\Billing\Markets\MarketResolver;
use App\Billing\PlanCatalog;
use App\Billing\PlanLimits;
use App\Billing\YooKassa\YooKassaGateway;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CheckoutRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Flash;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class BillingController extends Controller
{
    public function show(
        Request $request,
        CurrentWorkspace $current,
        PlanCatalog $catalog,
        PlanLimits $limits,
        MarketResolver $markets,
        BillingGateways $gateways,
    ): Response {
        $this->authorize('billing.view');
        $workspace = $this->workspace($current);
        $subscription = $workspace->subscription;
        $market = $markets->forWorkspace($workspace);
        $currency = $subscription !== null && $subscription->grantsPlan() && $subscription->currency !== null
            ? $subscription->currency
            : $market->currency;
        $available = $gateways->available($market->gateway);
        $managingGateway = $gateways->driverNameForSubscription($workspace, $subscription);
        $gateway = $gateways->available($managingGateway) ? $gateways->driver($managingGateway) : null;

        return Inertia::render('settings/Billing', [
            'subscription' => $subscription?->toContract(),
            'plans' => $catalog->all($currency),
            'usage' => $limits->usage($workspace),
            'currentPlan' => $workspace->planKey(),
            'checkout' => in_array($request->query('checkout'), ['success', 'cancelled', 'updated'], true)
                ? $request->query('checkout')
                : null,
            'market' => [
                ...$market->toContract(),
                'currency' => $currency,
                'country_name' => MarketResolver::countryName($market->country),
                'gateway_name' => (string) __("billing.gateways_inline.{$market->gateway}"),
                'available' => $available,
            ],
            'capabilities' => [
                'portal' => $gateway !== null && $gateway->driver() === 'stripe',
                'manage' => $gateway instanceof ManagesSubscriptions,
            ],
        ]);
    }

    public function checkout(CheckoutRequest $request, CurrentWorkspace $current, BillingGateways $gateways): SymfonyResponse
    {
        $workspace = $this->workspace($current);
        /** @var User $user */
        $user = $request->user();

        try {
            $url = $gateways->forWorkspace($workspace)->createCheckoutSession(
                $workspace,
                $user,
                (string) $request->validated('plan'),
                $request->billingInterval(),
                route('settings.billing', ['checkout' => 'success']),
                route('settings.billing', ['checkout' => 'cancelled']),
            );
        } catch (BillingException $exception) {
            Flash::errorMessage($exception->userMessage());

            return back();
        }

        return Inertia::location($url);
    }

    public function changePlan(CheckoutRequest $request, CurrentWorkspace $current, BillingGateways $gateways): RedirectResponse
    {
        $workspace = $this->workspace($current);
        $plan = (string) $request->validated('plan');
        $interval = $request->billingInterval();

        return $this->lifecycle($workspace, $gateways, function (ManagesSubscriptions $gateway, Subscription $subscription) use ($workspace, $plan, $interval): string {
            if ($subscription->plan === $plan && $subscription->interval === $interval->value && ! $subscription->cancel_at_period_end) {
                throw new BillingException('nothing_to_change');
            }

            $gateway->changePlan($workspace, $subscription, $plan, $interval);

            return $gateway instanceof YooKassaGateway ? 'billing.plan_change_scheduled' : 'billing.plan_change_requested';
        });
    }

    public function cancel(Request $request, CurrentWorkspace $current, BillingGateways $gateways): RedirectResponse
    {
        $this->authorize('billing.manage');
        $workspace = $this->workspace($current);

        return $this->lifecycle($workspace, $gateways, function (ManagesSubscriptions $gateway, Subscription $subscription) use ($workspace): string {
            $gateway->cancelAtPeriodEnd($workspace, $subscription);

            return 'billing.cancel_requested';
        });
    }

    public function resume(Request $request, CurrentWorkspace $current, BillingGateways $gateways): RedirectResponse
    {
        $this->authorize('billing.manage');
        $workspace = $this->workspace($current);

        return $this->lifecycle($workspace, $gateways, function (ManagesSubscriptions $gateway, Subscription $subscription) use ($workspace): string {
            $gateway->resume($workspace, $subscription);

            return 'billing.resume_requested';
        });
    }

    public function portal(Request $request, CurrentWorkspace $current, BillingGateways $gateways): SymfonyResponse
    {
        $this->authorize('billing.manage');
        $workspace = $this->workspace($current);

        try {
            $url = $gateways->driver($gateways->driverNameForSubscription($workspace, $workspace->subscription))
                ->createPortalSession($workspace, route('settings.billing'));
        } catch (BillingException $exception) {
            Flash::errorMessage($exception->userMessage());

            return back();
        }

        if ($url === null) {
            Flash::success('billing.portal_unavailable_fake');

            return back();
        }

        return Inertia::location($url);
    }

    /** @param  callable(ManagesSubscriptions, Subscription): string  $action */
    private function lifecycle(Workspace $workspace, BillingGateways $gateways, callable $action): RedirectResponse
    {
        $subscription = $workspace->subscription;

        try {
            if ($subscription === null || ! $subscription->grantsPlan()) {
                throw new BillingException('no_subscription');
            }

            $gateway = $gateways->forSubscription($workspace, $subscription);

            if (! $gateway instanceof ManagesSubscriptions) {
                throw new BillingException('not_supported');
            }

            $message = $action($gateway, $subscription);
        } catch (BillingException $exception) {
            Flash::errorMessage($exception->userMessage());

            return back();
        }

        Flash::success($message);

        return redirect()->route('settings.billing', ['checkout' => 'updated']);
    }

    private function workspace(CurrentWorkspace $current): Workspace
    {
        $workspace = $current->get();
        abort_if($workspace === null, 404);

        return $workspace;
    }
}
