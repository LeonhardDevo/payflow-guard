<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\NotificationFeed;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class NotificationsController extends Controller
{
    public function __invoke(Request $request, NotificationFeed $feed, CurrentWorkspace $current): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'id' => ['nullable', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $workspace = $current->get();
        $id = $validated['id'] ?? null;

        $marked = $feed->markRead($user, $workspace, is_string($id) ? $id : null);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'marked' => $marked,
                'unread' => $feed->for($user, $workspace)['unread'],
            ]);
        }

        return back(fallback: route('dashboard'));
    }
}
