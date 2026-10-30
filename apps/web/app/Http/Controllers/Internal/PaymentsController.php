<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Billing\Payments\PaymentReconciler;
use App\Billing\Payments\StripeAccountEventProcessor;
use App\Http\Controllers\Controller;
use App\Models\PaymentAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaymentsController extends Controller
{
    public function webhookSecret(PaymentAccount $account): JsonResponse
    {
        $secret = $account->credential('webhook_secret');

        if ($account->driver !== 'stripe' || $account->connected_at === null || $secret === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json(['account_id' => $account->id, 'secret' => $secret]);
    }

    public function stripeEvent(Request $request, StripeAccountEventProcessor $processor): JsonResponse
    {
        $accountId = $request->json('account_id');
        $event = $request->json('event');

        if (! is_int($accountId) || ! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null) || ($event['object'] ?? null) !== 'event') {
            return response()->json(['error' => 'invalid_event'], 422);
        }

        $account = PaymentAccount::query()->whereKey($accountId)->where('driver', 'stripe')->first();

        if ($account === null) {
            return response()->json(['error' => 'unknown_account'], 404);
        }

        return response()->json(['status' => $processor->handle($account, $event), 'event_id' => $event['id']]);
    }

    public function reconcile(PaymentReconciler $reconciler): JsonResponse
    {
        return response()->json($reconciler->run());
    }
}
