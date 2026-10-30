<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\AiQueryRequest;
use App\Models\AiQuery;
use App\Support\RequestId;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class AiQueriesController extends Controller
{
    public function __invoke(AiQueryRequest $request, RequestId $requestId): JsonResponse
    {
        $id = $requestId->get();
        $existing = AiQuery::query()->forWorkspace((int) $request->validated('workspace_id'))->where('request_id', $id)->value('id');

        if ($existing !== null) {
            return response()->json(['id' => $existing, 'duplicate' => true]);
        }

        $data = $request->validated();

        $query = new AiQuery;
        $query->fill([
            'workspace_id' => (int) $data['workspace_id'],
            'user_id' => isset($data['user_id']) ? (int) $data['user_id'] : null,
            'channel' => $data['channel'],
            'question' => $data['question'],
            'answer' => $data['answer'] ?? null,
            'refused' => (bool) $data['refused'],
            'confidence' => isset($data['confidence']) ? (float) $data['confidence'] : null,
            'citations' => array_values(array_map(
                fn (array $citation): array => [
                    'document_id' => (int) $citation['document_id'],
                    'title' => (string) $citation['title'],
                    'page' => isset($citation['page']) ? (int) $citation['page'] : null,
                ],
                $data['citations'] ?? [],
            )),
            'tools_used' => array_values($data['tools_used'] ?? []),
            'latency_ms' => isset($data['latency_ms']) ? (int) $data['latency_ms'] : null,
            'provider' => $data['provider'] ?? null,
            'model' => $data['model'] ?? null,
            'request_id' => $id,
        ]);

        try {
            DB::transaction(fn (): bool => $query->save());
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'id' => AiQuery::query()->withoutGlobalScopes()->where('request_id', $id)->value('id'),
                'duplicate' => true,
            ]);
        }

        return response()->json(['id' => $query->id], 201);
    }
}
