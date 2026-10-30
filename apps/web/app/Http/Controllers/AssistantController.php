<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ai\AiClient;
use App\Ai\AiServiceException;
use App\Assistant\Actions\AssistantActionContext;
use App\Assistant\Actions\AssistantActionRegistry;
use App\Enums\AiChannel;
use App\Http\Requests\Assistant\AskRequest;
use App\Http\Requests\Assistant\AssistantActionRequest;
use App\Models\AiQuery;
use App\Models\User;
use App\Support\Paginated;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class AssistantController extends Controller
{
    public function index(): Response
    {
        $this->authorize('assistant.use');

        /** @var array<string, list<string>> $suggestions */
        $suggestions = config('product.assistant_suggestions', []);

        return Inertia::render('assistant/Index', [
            'suggestions' => $suggestions[App::getLocale()] ?? $suggestions['en'] ?? [],
        ]);
    }

    public function ask(AskRequest $request, CurrentWorkspace $current, AiClient $ai): JsonResponse
    {
        $workspace = $current->get();
        $role = $current->role();
        abort_if($workspace === null || $role === null, 404);

        /** @var User $user */
        $user = $request->user();

        $payload = [
            'workspace_id' => $workspace->id,
            'user' => [
                'id' => $user->id,
                'role' => $role->value,
                'locale' => App::getLocale(),
                'permissions' => $role->abilities(),
            ],
            'channel' => AiChannel::Web->value,
            'message' => (string) $request->validated('message'),
            'ai_external_allowed' => $workspace->ai_external_allowed,
        ];

        $conversationId = $request->validated('conversation_id');

        if (is_string($conversationId) && $conversationId !== '') {
            $payload['conversation_id'] = $conversationId;
        }

        try {
            return response()->json($ai->chat($payload));
        } catch (AiServiceException $exception) {
            return response()->json([
                'message' => __($exception->isUnavailable()
                    ? 'notifications.assistant.unavailable'
                    : 'notifications.assistant.failed'),
            ], $exception->isUnavailable() ? 503 : 502);
        }
    }

    public function action(AssistantActionRequest $request, CurrentWorkspace $current, AssistantActionRegistry $registry): JsonResponse
    {
        $type = (string) $request->validated('type');
        $handler = $registry->resolve($type);

        if ($handler === null) {
            return response()->json([
                'ok' => false,
                'error' => 'unknown_action',
                'message' => __('notifications.assistant.action_unknown'),
            ], 404);
        }

        $workspace = $current->get();
        $role = $current->role();
        abort_if($workspace === null || $role === null, 404);

        /** @var User $user */
        $user = $request->user();

        $result = $handler->handle(new AssistantActionContext(
            type: $type,
            payload: $request->payload(),
            channel: AiChannel::Web,
            user: $user,
            workspace: $workspace,
            role: $role,
            locale: App::getLocale(),
        ));

        return response()->json(
            array_intersect_key($result->toArray(), array_flip(['ok', 'message'])),
            $result->ok ? 200 : 422,
        );
    }

    public function history(Request $request): Response
    {
        $this->authorize('assistant.history');

        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['answered', 'refused'])],
            'channel' => ['nullable', 'string', Rule::in(AiChannel::values())],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $queries = AiQuery::query()
            ->with('user:id,name')
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('refused', $status === 'refused'))
            ->when($filters['channel'] ?? null, fn (Builder $query, string $channel) => $query->where('channel', $channel))
            ->when($filters['q'] ?? null, fn (Builder $query, string $q) => $query->where(
                'question',
                'ilike',
                '%'.addcslashes($q, '%_\\').'%',
            ))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $stats = AiQuery::query()
            ->selectRaw('count(*) as total')
            ->selectRaw('count(*) filter (where refused = false) as answered')
            ->selectRaw('count(*) filter (where refused = true) as refused')
            ->selectRaw('coalesce(avg(confidence), 0) as avg_confidence')
            ->toBase()
            ->first();

        return Inertia::render('assistant/History', [
            'queries' => Paginated::from($queries, fn (AiQuery $query): array => $query->toContract()),
            'filters' => array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== ''),
            'stats' => [
                'total' => (int) ($stats->total ?? 0),
                'answered' => (int) ($stats->answered ?? 0),
                'refused' => (int) ($stats->refused ?? 0),
                'avg_confidence' => round((float) ($stats->avg_confidence ?? 0), 4),
            ],
        ]);
    }
}
