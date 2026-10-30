<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyInternalSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'service' => config('internal.service', 'web'),
            'caller' => $request->attributes->get(VerifyInternalSignature::ATTRIBUTE_SERVICE),
            'time' => now()->toIso8601String(),
        ]);
    }
}
