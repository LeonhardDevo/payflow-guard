<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Assistant\Actions\AssistantActionContext;
use App\Assistant\Actions\AssistantActionRegistry;
use App\Billing\PlanLimits;
use App\Enums\AiChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\TelegramActionRequest;
use App\Http\Requests\Internal\TelegramLinkRequest;
use App\Http\Requests\Internal\TelegramUnlinkRequest;
use App\Locale\Locales;
use App\Locale\WithLocale;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Telegram\TelegramLinker;
use App\Telegram\TelegramLinkException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TelegramController extends Controller
{
    public function link(TelegramLinkRequest $request, TelegramLinker $linker): JsonResponse
    {
        try {
            $account = $linker->link((string) $request->validated('token'), [
                'telegram_user_id' => (int) $request->validated('telegram_user_id'),
                'chat_id' => (int) $request->validated('chat_id'),
                'username' => $request->validated('username'),
                'language_code' => $request->validated('language_code'),
            ]);
        } catch (TelegramLinkException $exception) {
            $status = match ($exception->reason) {
                'invalid_token' => 404,
                'already_linked' => 409,
                default => 410,
            };

            return response()->json(['error' => $exception->reason], $status);
        }

        return response()->json(['linked' => true, ...$this->contextFor($account)]);
    }

    public function context(Request $request): JsonResponse
    {
        $telegramUserId = $request->query('telegram_user_id');
        $userId = $request->query('user_id');

        $account = match (true) {
            is_numeric($telegramUserId) => TelegramAccount::query()->where('telegram_user_id', (int) $telegramUserId)->first(),
            is_numeric($userId) => TelegramAccount::query()->where('user_id', (int) $userId)->first(),
            default => false,
        };

        if ($account === false) {
            return response()->json(['error' => 'validation_failed', 'errors' => [
                'telegram_user_id' => ['telegram_user_id or user_id is required.'],
            ]], 422);
        }

        if ($account === null) {
            return response()->json(['error' => 'not_linked'], 404);
        }

        return response()->json($this->contextFor($account));
    }

    public function unlink(TelegramUnlinkRequest $request, TelegramLinker $linker): JsonResponse
    {
        $account = TelegramAccount::query()->where('telegram_user_id', (int) $request->validated('telegram_user_id'))->first();
        $user = $account?->user;

        if ($user === null || ! $linker->unlink($user)) {
            return response()->json(['error' => 'not_linked'], 404);
        }

        return response()->json(['unlinked' => true]);
    }

    public function action(TelegramActionRequest $request, AssistantActionRegistry $registry): JsonResponse
    {
        $type = (string) $request->validated('type');
        $handler = $registry->resolve($type);

        if ($handler === null) {
            return response()->json(['ok' => false, 'error' => 'unknown_action'], 404);
        }

        $account = TelegramAccount::query()->where('telegram_user_id', (int) $request->validated('telegram_user_id'))->first();
        $user = $account?->user;

        if ($user !== null && $request->validated('user_id') !== null && (int) $request->validated('user_id') !== $user->id) {
            return response()->json(['ok' => false, 'error' => 'user_mismatch'], 403);
        }

        $workspace = $account !== null && $user !== null
            ? $this->resolveWorkspace($account, $user, $request->validated('workspace_id'))
            : null;

        $requestedLocale = $request->validated('locale');
        $locale = $user->locale
            ?? (is_string($requestedLocale) && Locales::isSupported(substr($requestedLocale, 0, 2)) ? substr($requestedLocale, 0, 2) : Locales::fallback());

        $payload = $request->validated('payload');

        $context = new AssistantActionContext(
            type: $type,
            payload: is_array($payload) ? $payload : [],
            channel: AiChannel::Telegram,
            user: $user,
            workspace: $workspace,
            role: $workspace !== null ? $user->roleIn($workspace) : null,
            locale: $locale,
            telegramUserId: (int) $request->validated('telegram_user_id'),
            chatId: (int) $request->validated('chat_id'),
        );

        $result = WithLocale::run($locale, fn () => $handler->handle($context));

        return response()->json($result->toArray());
    }

    /** @return array<string, mixed> */
    private function contextFor(TelegramAccount $account): array
    {
        /** @var User $user */
        $user = $account->user;
        $workspace = $this->resolveWorkspace($account, $user);
        $role = $workspace !== null ? $user->roleIn($workspace) : null;

        return [
            'user' => ['id' => $user->id, 'name' => $user->name, 'locale' => $user->locale],
            'workspace' => $workspace !== null
                ? [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'plan' => $workspace->planKey(),
                    'ai_external_allowed' => $workspace->ai_external_allowed,
                ]
                : null,
            'role' => $role?->value,
            'permissions' => $role?->abilities() ?? [],
            'ai_quota' => $workspace !== null ? [
                'used' => app(PlanLimits::class)->used($workspace, 'ai_queries_monthly'),
                'limit' => app(PlanLimits::class)->limit($workspace, 'ai_queries_monthly'),
            ] : null,
            'telegram' => [
                'user_id' => $account->telegram_user_id,
                'chat_id' => $account->chat_id,
                'username' => $account->username,
            ],
        ];
    }

    private function resolveWorkspace(TelegramAccount $account, User $user, mixed $preferred = null): ?Workspace
    {
        $candidates = array_filter([
            is_numeric($preferred) ? (int) $preferred : null,
            $account->workspace_id,
            $user->current_workspace_id,
        ]);

        foreach ($candidates as $workspaceId) {
            if ($user->belongsToWorkspace($workspaceId)) {
                return Workspace::query()->find($workspaceId);
            }
        }

        $workspace = $user->workspaces()->orderBy('workspace_user.created_at')->first();

        if ($workspace !== null && $account->workspace_id !== $workspace->id) {
            $account->forceFill(['workspace_id' => $workspace->id])->save();
        }

        return $workspace;
    }
}
