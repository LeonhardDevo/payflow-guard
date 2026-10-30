<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Flash;
use App\Telegram\TelegramLinker;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class IntegrationsController extends Controller
{
    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $account = $user->telegramAccount;

        return Inertia::render('settings/Integrations', [
            'telegram' => [
                'linked' => $account !== null,
                'username' => $account?->username,
                'linked_at' => $account?->linked_at->toIso8601String(),
            ],
            'botUsername' => config('product.telegram.bot_username'),
        ]);
    }

    public function link(Request $request, TelegramLinker $linker, CurrentWorkspace $current): JsonResponse
    {
        $this->authorize('integrations.manage');

        if (config('product.telegram.bot_username') === null) {
            return response()->json(['message' => __('notifications.telegram.not_configured')], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $link = $linker->issueLink($user, $current->get());

        return response()->json([
            'url' => $link['url'],
            'expires_at' => $link['expires_at']->toIso8601String(),
        ]);
    }

    public function unlink(Request $request, TelegramLinker $linker): RedirectResponse
    {
        $this->authorize('integrations.manage');

        /** @var User $user */
        $user = $request->user();

        if ($linker->unlink($user)) {
            Flash::success('notifications.telegram.unlinked');
        }

        return back();
    }
}
