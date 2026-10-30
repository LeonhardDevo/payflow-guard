<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final readonly class AssignRequestId
{
    public function __construct(private RequestId $requestId) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(RequestId::HEADER);

        if (RequestId::isValid($incoming)) {
            $this->requestId->set((string) $incoming);
        } else {
            $this->requestId->set((string) Str::uuid());
        }

        $id = $this->requestId->get();
        $request->attributes->set('request_id', $id);
        Log::shareContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set(RequestId::HEADER, $id);

        return $response;
    }
}
