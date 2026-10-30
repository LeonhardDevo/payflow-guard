<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

final class AiServiceException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $detail = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public static function unreachable(Throwable $previous): self
    {
        return new self('AI service unreachable', 503, null, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $detail = $response->json('detail') ?? $response->json('error') ?? $response->json('message');

        return new self(
            'AI service error',
            $response->status() >= 500 ? 502 : $response->status(),
            is_string($detail) ? $detail : null,
        );
    }

    public function isUnavailable(): bool
    {
        return $this->status === 503;
    }
}
