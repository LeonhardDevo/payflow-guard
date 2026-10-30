<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\User;

it('renders the marketing pages', function (): void {
    $this->get(route('home'))->assertOk()->assertInertia(fn ($page) => $page->component('marketing/Landing', false)->where('canRegister', true)->has('plans', 4)->where('plans.0.key', 'free'));
    $this->get(route('security'))->assertOk()->assertInertia(fn ($page) => $page->component('marketing/Security', false));
    $this->get(route('legal', 'terms'))->assertOk()->assertInertia(fn ($page) => $page->component('marketing/Legal', false)->where('doc.key', 'terms'));
    $this->get(route('legal', 'privacy'))->assertInertia(fn ($page) => $page->where('doc.key', 'privacy'));
    $this->get('/legal/no-such-document')->assertNotFound();
    $this->get('/legal/Not_A_Key')->assertNotFound();
});

it('redirects guests from the app to login', function (string $path): void {
    $this->get($path)->assertRedirect(route('login'));
})->with(['/dashboard', '/knowledge', '/assistant', '/audit', '/settings/members', '/admin/ai', '/onboarding/workspace']);

it('requires a verified email for the app', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
});

it('renders the dashboard KPI placeholder', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    Document::factory()->count(3)->create(['workspace_id' => $workspace->id, 'size_bytes' => 1048576]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('stats.0', ['key' => 'members', 'label' => __('billing.limits.seats'), 'value' => 1])
            ->where('stats.1.key', 'documents')
            ->where('stats.1.value', 3)
            ->where('stats.2.key', 'ai_queries')
            ->where('stats.2.delta', null)
            ->where('stats.3', ['key' => 'storage_mb', 'label' => __('billing.limits.storage_mb'), 'value' => 3]));
});

it('redirects onboarding to the dashboard for users who already have a workspace', function (): void {
    $this->signInMember();

    $this->get(route('onboarding.workspace'))->assertRedirect(route('dashboard'));
    $this->get(route('onboarding.workspace', ['new' => 1]))->assertOk();
});
