<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Billing\StripeEventProcessor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StripeEventsController extends Controller
{
    public function __invoke(Request $request, StripeEventProcessor $processor): JsonResponse
    {
        $event = $request->json()->all();

        if (! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null) || ($event['object'] ?? null) !== 'event') {
            return response()->json(['error' => 'invalid_event'], 422);
        }

        $result = $processor->handle($event);

        return response()->json(['status' => $result, 'event_id' => $event['id']]);
    }
}
