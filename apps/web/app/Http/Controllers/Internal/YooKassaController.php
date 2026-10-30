<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Billing\BillingException;
use App\Billing\Markets\MarketResolver;
use App\Billing\Payments\PaymentLinks;
use App\Billing\YooKassa\YooKassaPaymentProcessor;
use App\Billing\YooKassa\YooKassaRenewals;
use App\Http\Controllers\Controller;
use App\Models\PaymentLinkRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class YooKassaController extends Controller
{
    private const array EVENTS = ['payment.succeeded', 'payment.canceled', 'payment.waiting_for_capture'];

    public function notification(
        Request $request,
        YooKassaPaymentProcessor $processor,
        PaymentLinks $links,
        MarketResolver $markets,
    ): JsonResponse {
        $event = $request->json('event');
        $paymentId = $request->json('object.id');

        if (! is_string($event) || ! in_array($event, self::EVENTS, true) || ! is_string($paymentId) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $paymentId) !== 1) {
            return response()->json(['error' => 'invalid_notification'], 422);
        }

        $clientPayment = PaymentLinkRecord::query()->with('account')->where('driver', 'yookassa')->where('provider_id', $paymentId)->first();

        try {
            if ($clientPayment !== null) {
                $status = $links->refresh($clientPayment, 'yookassa.'.$event)->status->value;

                return response()->json(['status' => 'client_payment', 'payment_status' => $status, 'payment_id' => $paymentId]);
            }

            if (! $markets->gatewayAvailable('yookassa')) {
                return response()->json(['error' => 'gateway_unavailable'], 503);
            }

            $status = $processor->handle($paymentId);
        } catch (BillingException $exception) {
            return response()->json(['error' => $exception->reason], 502);
        }

        return response()->json(['status' => $status, 'payment_id' => $paymentId]);
    }

    public function renewals(YooKassaRenewals $renewals): JsonResponse
    {
        return response()->json($renewals->run());
    }
}
