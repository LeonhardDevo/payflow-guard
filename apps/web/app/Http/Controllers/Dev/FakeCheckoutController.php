<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Billing\BillingException;
use App\Billing\Fake\FakeCheckoutSession;
use App\Billing\Fake\FakeCheckoutStore;
use App\Billing\Fake\FakeStripeEvents;
use App\Billing\Fake\FakeWebhookDispatcher;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Flash;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class FakeCheckoutController extends Controller
{
    public function show(Request $request, string $session, FakeCheckoutStore $store): Response
    {
        $checkout = $this->session($request, $session, $store);
        $workspace = Workspace::query()->findOrFail($checkout->workspaceId);

        return Inertia::render('dev/FakeCheckout', [
            'session' => [
                'id' => $checkout->id,
                'kind' => 'subscription',
                'title' => __('billing.fake_checkout.title', [
                    'plan' => __("billing.plans.{$checkout->plan}.name"),
                    'interval' => __("billing.intervals.{$checkout->interval->value}"),
                ]),
                'amount' => $checkout->amount / 100,
                'currency' => strtoupper($checkout->currency),
                'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
                'success_url' => $checkout->successUrl,
                'cancel_url' => $checkout->cancelUrl,
            ],
        ]);
    }

    public function pay(
        Request $request,
        string $session,
        FakeCheckoutStore $store,
        FakeStripeEvents $events,
        FakeWebhookDispatcher $dispatcher,
    ): SymfonyResponse|RedirectResponse {
        $checkout = $this->session($request, $session, $store);

        try {
            $dispatcher->dispatch($events->forPaidCheckout($checkout));
        } catch (BillingException $exception) {
            Flash::errorMessage($exception->userMessage());

            return back();
        }

        $store->forget($checkout->id);
        Flash::success('billing.checkout_completed');

        return redirect()->to($checkout->successUrl);
    }

    private function session(Request $request, string $id, FakeCheckoutStore $store): FakeCheckoutSession
    {
        $checkout = $store->find($id);
        abort_if($checkout === null, 404);

        /** @var User $user */
        $user = $request->user();
        abort_unless($user->roleIn($checkout->workspaceId)?->allows('billing.manage') ?? false, 403);

        return $checkout;
    }
}
