<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal\Payflow;

use App\Domain\Payments\Links\PaymentLinkSync;
use App\Domain\Payments\Models\InvoicePaymentLink;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaymentLinksController extends Controller
{
    public function candidates(Request $request, PaymentLinkSync $sync): JsonResponse
    {
        $limit = (int) $request->query('limit', (string) config('payflow.payment_links.sync_batch', 100));

        return response()->json(['data' => $sync->candidates(max(1, min($limit, 500)))]);
    }

    public function sync(int $link, PaymentLinkSync $sync): JsonResponse
    {
        $model = InvoicePaymentLink::query()->whereKey($link)->whereNotNull('invoice_id')->first();

        if ($model === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json($sync->sync($model));
    }
}
