<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Internal\InternalKeyring;
use App\Internal\InternalSignature;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

final readonly class VerifyInternalSignature
{
    public const string ATTRIBUTE_SERVICE = 'internal_service';

    public function __construct(private InternalKeyring $keyring) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $service = (string) $request->headers->get(InternalSignature::SERVICE_HEADER, '');
        /** @var list<string> $allowed */
        $allowed = config('internal.allowed_callers', []);

        $secret = in_array($service, $allowed, true) ? $this->keyring->secretFor($service) : null;

        $valid = $secret !== null && (new InternalSignature($secret, (int) config('internal.tolerance', 300)))->verify(
            $request->headers->get(InternalSignature::HEADER),
            $request->getMethod(),
            $request->getRequestUri(),
            (string) $request->getContent(),
        );

        if (! $valid) {
            Log::warning('internal.signature_rejected', [
                'service' => $service,
                'method' => $request->getMethod(),
                'path' => $request->path(),
            ]);

            return new JsonResponse(['error' => 'invalid_signature'], Response::HTTP_UNAUTHORIZED);
        }

        $request->attributes->set(self::ATTRIBUTE_SERVICE, $service);
        self::usePublicRoot();

        return $next($request);
    }

    private static function usePublicRoot(): void
    {
        $root = rtrim((string) config('app.url'), '/');

        if ($root === '') {
            return;
        }

        URL::forceRootUrl($root);

        if (str_starts_with($root, 'https://')) {
            URL::forceScheme('https');
        }
    }
}
