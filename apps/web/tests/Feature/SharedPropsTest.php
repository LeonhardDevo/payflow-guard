<?php

declare(strict_types=1);

use App\Authorization\Abilities;
use App\Enums\WorkspaceRole;

it('shares exactly the contract props for an authenticated member', function (): void {
    [$user, $workspace] = $this->signInMember(WorkspaceRole::Admin);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('name', config('app.name'))
            ->where('product.slug', config('product.slug'))
            ->where('product.tagline', config('product.tagline'))
            ->where('product.telegramBotUsername', 'test_bot')
            ->where('locale', 'en')
            ->where('availableLocales', [['code' => 'en', 'label' => 'English'], ['code' => 'ru', 'label' => 'Русский']])
            ->where('auth.user.id', $user->id)
            ->where('auth.user.email', $user->email)
            ->where('auth.user.locale', 'en')
            ->where('auth.user.avatar', null)
            ->where('auth.user.is_platform_admin', false)
            ->has('auth.user.email_verified_at')
            ->where('workspace', [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'role' => 'admin',
                'plan' => 'free',
                'timezone' => 'UTC',
                'currency' => 'USD',
                'country' => null,
            ])
            ->where('workspaces', [[
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'role' => 'admin',
            ]])
            ->has('can')
            ->where('flash', ['success' => null, 'error' => null])
            ->where('billing', ['driver' => 'fake', 'alert' => null])
            ->where('sidebarOpen', true)
            ->has('stats', 4));
});

it('shares null auth and workspace for guests', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('marketing/Landing', false)
            ->where('auth.user', null)
            ->where('workspace', null)
            ->where('workspaces', [])
            ->where('canRegister', true));
});

it('shares translated flash messages', function (): void {
    $this->signInMember();

    $this->withSession(['success' => 'Saved!', 'error' => null])
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('flash.success', 'Saved!'));
});

it('reflects the sidebar cookie', function (): void {
    $this->signInMember();

    $this->withUnencryptedCookie('sidebar_state', 'false')
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('sidebarOpen', false));
});

it('never lets page props shadow the shared workspace and can props', function (string $routeName): void {
    [$user, $workspace] = $this->signInMember(WorkspaceRole::Admin);

    $this->get(route($routeName))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.id', $workspace->id)
            ->where('workspace.role', 'admin')
            ->where('workspace.plan', 'free')
            ->where('can', app(Abilities::class)->map($user))
            ->etc());
})->with(['dashboard', 'settings.privacy']);
