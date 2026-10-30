<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class EnsureInternalCaller
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, string ...$callers): Response
    {
        $service = $request->attributes->get(VerifyInternalSignature::ATTRIBUTE_SERVICE);

        if (! is_string($service) || ! in_array($service, $callers, true)) {
            Log::warning('internal.caller_not_allowed', [
                'service' => $service,
                'method' => $request->getMethod(),
                'path' => $request->path(),
            ]);

            return new JsonResponse(['error' => 'caller_not_allowed'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
