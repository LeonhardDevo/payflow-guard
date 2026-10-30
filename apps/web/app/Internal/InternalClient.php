<?php

declare(strict_types=1);

namespace App\Internal;

use App\Support\RequestId;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

final readonly class InternalClient
{
    public function __construct(
        private HttpFactory $http,
        private InternalKeyring $keyring,
        private RequestId $requestId,
    ) {}

    public function to(string $service): PendingRequest
    {
        $baseUrl = config("internal.services.{$service}.url");

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new \InvalidArgumentException("Unknown internal service [{$service}].");
        }

        $secret = $this->keyring->secretFor($service)
            ?? throw new \InvalidArgumentException("No internal pair key for service [{$service}].");
        $signature = new InternalSignature($secret, (int) config('internal.tolerance', 300));

        return $this->http
            ->baseUrl(rtrim($baseUrl, '/'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('internal.timeout', 30))
            ->connectTimeout(5)
            ->withHeaders([
                InternalSignature::SERVICE_HEADER => (string) config('internal.service', 'web'),
                RequestId::HEADER => $this->requestId->get(),
            ])
            ->withRequestMiddleware(fn (RequestInterface $request): RequestInterface => self::signRequest($signature, $request));
    }

    private static function signRequest(InternalSignature $signature, RequestInterface $request): RequestInterface
    {
        $body = $request->getBody();
        $contents = (string) $body;

        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $request->withHeader(
            InternalSignature::HEADER,
            $signature->sign($request->getMethod(), self::pathWithQuery($request->getUri()), $contents),
        );
    }

    public static function pathWithQuery(UriInterface $uri): string
    {
        $path = $uri->getPath() === '' ? '/' : $uri->getPath();
        $query = $uri->getQuery();

        return $query === '' ? $path : $path.'?'.$query;
    }
}
