<?php

declare(strict_types=1);

namespace App\Ai;

use App\Internal\InternalClient;
use App\Legal\AiProviderDisclosure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

final readonly class AiClient
{
    public function __construct(private InternalClient $internal) {}

    /**
     * @param  array{workspace_id: int, user: array{id: int, role: string, locale: string, permissions: list<string>}, channel: string, conversation_id?: string|null, message: string, ai_external_allowed?: bool}  $payload
     * @return array<string, mixed>
     *
     * @throws AiServiceException
     */
    public function chat(array $payload): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->timeout(90)->post('/v1/chat', $payload));
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws AiServiceException
     */
    public function providers(?string $locale = null): array
    {
        $query = $locale !== null ? ['locale' => $locale] : [];

        /** @var list<array<string, mixed>> */
        return array_values($this->send(fn (PendingRequest $request): Response => $request->get('/v1/admin/ai/providers', $query)));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AiServiceException
     */
    public function models(string $provider): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->get('/v1/admin/ai/providers/'.rawurlencode($provider).'/models'));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AiServiceException
     */
    public function settings(): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->get('/v1/admin/ai/settings'));
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     *
     * @throws AiServiceException
     */
    public function updateSettings(array $settings): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->put('/v1/admin/ai/settings', $settings));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AiServiceException
     */
    public function updateKey(string $provider, ?string $apiKey): array
    {
        $result = $this->send(fn (PendingRequest $request): Response => $request->put(
            '/v1/admin/ai/keys/'.rawurlencode($provider),
            ['api_key' => $apiKey],
        ));
        Cache::forget(AiProviderDisclosure::CACHE_KEY);

        return $result;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AiServiceException
     */
    public function test(): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->timeout(60)->post('/v1/admin/ai/test'));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AiServiceException
     */
    public function usage(int $days = 30): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->get('/v1/admin/ai/usage', ['days' => $days]));
    }

    /**
     * @return list<array{page: int|null, section: string|null, content: string}>
     *
     * @throws AiServiceException
     */
    public function documentChunks(int $documentId, int $workspaceId, int $limit = 5): array
    {
        $json = $this->send(fn (PendingRequest $request): Response => $request
            ->timeout(5)
            ->get('/v1/documents/'.$documentId.'/chunks', ['workspace_id' => $workspaceId, 'limit' => $limit]));

        $rows = array_is_list($json) ? $json : ($json['chunks'] ?? []);

        $chunks = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $section = $row['section'] ?? $row['section_path'] ?? null;

            $chunks[] = [
                'page' => is_numeric($row['page'] ?? null) ? (int) $row['page'] : null,
                'section' => is_array($section) ? implode(' › ', array_map('strval', $section)) : (is_string($section) ? $section : null),
                'content' => (string) ($row['content'] ?? ''),
            ];
        }

        return $chunks;
    }

    /**
     * @param  \Closure(PendingRequest): Response  $call
     * @return array<array-key, mixed>
     *
     * @throws AiServiceException
     */
    private function send(\Closure $call): array
    {
        try {
            $response = $call($this->internal->to('ai'));
        } catch (ConnectionException $exception) {
            throw AiServiceException::unreachable($exception);
        }

        if ($response->failed()) {
            throw AiServiceException::fromResponse($response);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
