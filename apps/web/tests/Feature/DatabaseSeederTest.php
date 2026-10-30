<?php

declare(strict_types=1);

use App\Billing\PlanLimits;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

it('seeds the foundation demo data idempotently', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $admin = User::query()->where('email', 'admin@payflow.test')->sole();
    $owner = User::query()->where('email', 'demo@payflow.test')->sole();
    $member = User::query()->where('email', 'member@payflow.test')->sole();
    $workspace = Workspace::query()->where('slug', 'demo-studio')->sole();

    expect($admin->is_platform_admin)->toBeTrue()
        ->and($owner->is_platform_admin)->toBeFalse()
        ->and(Hash::check('password', $owner->password))->toBeTrue()
        ->and($owner->hasVerifiedEmail())->toBeTrue()
        ->and($owner->roleIn($workspace))->toBe(WorkspaceRole::Owner)
        ->and($admin->roleIn($workspace))->toBe(WorkspaceRole::Member)
        ->and($member->roleIn($workspace))->toBe(WorkspaceRole::Member)
        ->and($workspace->name)->toBe('Northstar Digital')
        ->and($member->locale)->toBe('ru')
        ->and(WorkspaceInvitation::query()->pending()->count())->toBe(1)
        ->and(AuditLog::query()->where('workspace_id', $workspace->id)->count())->toBeGreaterThan(5)
        ->and(User::query()->count())->toBe(13);

    $limits = app(PlanLimits::class);
    expect($workspace->subscription()->sole()->plan)->toBe('pro')
        ->and($limits->used($workspace, 'seats'))->toBeLessThanOrEqual((int) $limits->limit($workspace, 'seats'));
});

it('lets the seeded demo owner sign in and reach the dashboard', function (): void {
    $this->seed(DatabaseSeeder::class);

    $this->post(route('login.store'), ['email' => 'demo@payflow.test', 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))->assertOk()->assertInertia(fn ($page) => $page->where('workspace.name', 'Northstar Digital'));
});
