<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Events\DeliveryReported;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\DeliveryRequest;
use App\Models\Delivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

final class DeliveriesController extends Controller
{
    public function __invoke(DeliveryRequest $request): JsonResponse
    {
        $delivery = Delivery::query()->create([
            'reference_type' => (string) $request->validated('reference.type'),
            'reference_id' => (string) $request->validated('reference.id'),
            'channel' => (string) $request->validated('channel'),
            'status' => (string) $request->validated('status'),
            'error' => $request->validated('error'),
            'provider_message_id' => $request->validated('provider_message_id'),
            'attempt' => $request->validated('attempt'),
            'next_retry_at' => $request->validated('next_retry_at'),
        ]);

        if ($delivery->status === 'failed') {
            Log::warning('delivery.failed', [
                'reference_type' => $delivery->reference_type,
                'reference_id' => $delivery->reference_id,
                'channel' => $delivery->channel,
            ]);
        }

        DeliveryReported::dispatch($delivery);

        return response()->json(['id' => $delivery->id], 201);
    }
}
