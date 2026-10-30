<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Authorization\Abilities;
use App\Billing\BillingBanner;
use App\Locale\Locales;
use App\Models\User;
use App\Notifications\NotificationFeed;
use App\Support\Flash;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /** @var string */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $user = $request->user();
        $user = $user instanceof User ? $user : null;
        $current = app(CurrentWorkspace::class);
        $workspace = $user !== null ? $current->get() : null;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'product' => [
                'slug' => (string) config('product.slug'),
                'tagline' => (string) config('product.tagline'),
                'telegramBotUsername' => config('product.telegram.bot_username'),
            ],
            'locale' => App::getLocale(),
            'availableLocales' => Locales::options(),
            'auth' => [
                'user' => $user !== null ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'locale' => $user->locale,
                    'avatar' => null,
                    'is_platform_admin' => $user->is_platform_admin,
                    'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                ] : null,
            ],
            'workspace' => $workspace !== null ? [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'role' => $current->role()?->value,
                'plan' => $workspace->planKey(),
                'timezone' => $workspace->timezone,
                'currency' => $workspace->currency,
                'country' => $workspace->country,
            ] : null,
            'workspaces' => fn (): array => $user !== null
                ? $user->workspaces()
                    ->orderBy('workspaces.name')
                    ->get(['workspaces.id', 'workspaces.name', 'workspaces.slug'])
                    ->map(fn ($item): array => [
                        'id' => $item->id,
                        'name' => $item->name,
                        'slug' => $item->slug,
                        'role' => $item->membership?->role->value,
                    ])
                    ->values()
                    ->all()
                : [],
            'can' => app(Abilities::class)->map($user),
            'flash' => [
                'success' => $request->hasSession() ? $request->session()->get(Flash::SUCCESS) : null,
                'error' => $request->hasSession() ? $request->session()->get(Flash::ERROR) : null,
            ],
            'billing' => fn (): array => app(BillingBanner::class)->for($workspace),

            'notifications' => fn (): ?array => $user !== null ? app(NotificationFeed::class)->for($user, $workspace) : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
