<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use App\Workspaces\WorkspaceService;

it('scopes tenant models to the current workspace', function (): void {
    [$user, $mine] = $this->signInMember();
    $foreign = Workspace::factory()->create();

    $own = Document::factory()->create(['workspace_id' => $mine->id]);
    Document::factory()->create(['workspace_id' => $foreign->id]);

    expect(Document::query()->pluck('id')->all())->toBe([$own->id]);
});

it('returns 404 for another workspace document (route binding is scoped)', function (): void {
    $this->signInMember();
    $foreignDocument = Document::factory()->create();

    $this->get(route('knowledge.show', $foreignDocument))->assertNotFound();
    $this->patch(route('knowledge.update', $foreignDocument), ['title' => 'x'])->assertNotFound();
    $this->delete(route('knowledge.destroy', $foreignDocument))->assertNotFound();
});

it('auto-fills workspace_id from the current workspace', function (): void {
    [, $workspace] = $this->signInMember();

    $document = Document::factory()->make(['workspace_id' => null]);
    $document->save();

    expect($document->workspace_id)->toBe($workspace->id);
});

it('refuses to create tenant rows without any workspace', function (): void {
    Document::factory()->make(['workspace_id' => null])->save();
})->throws(LogicException::class);

it('is inactive outside a user context and can be narrowed explicitly', function (): void {
    $a = Document::factory()->create();
    $b = Document::factory()->create();

    expect(Document::query()->count())->toBe(2)
        ->and(Document::query()->forWorkspace($a->workspace_id)->pluck('id')->all())->toBe([$a->id]);

    app(CurrentWorkspace::class)->runAs($b->workspace, function () use ($b): void {
        expect(Document::query()->pluck('id')->all())->toBe([$b->id]);
    });
});

it('ignores a tampered session pointing at a foreign workspace', function (): void {
    [$user, $mine] = $this->member();
    $foreign = Workspace::factory()->create();

    $this->actingAs($user)
        ->withSession([CurrentWorkspace::SESSION_KEY => $foreign->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('workspace.id', $mine->id));
});

it('switches between own workspaces and persists the choice', function (): void {
    [$user, $first] = $this->signInMember();
    [, $second] = $this->member(WorkspaceRole::Member, null, ['email' => 'other@example.com']);
    $second->addMember($user, WorkspaceRole::Admin);

    $this->post(route('workspaces.switch', $second))->assertRedirect(route('dashboard'));

    expect($user->refresh()->current_workspace_id)->toBe($second->id);

    $this->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('workspace.id', $second->id)
            ->where('workspace.role', 'admin')
            ->has('workspaces', 2));
});

it('forbids switching to a workspace the user does not belong to', function (): void {
    $this->signInMember();
    $foreign = Workspace::factory()->create();

    $this->post(route('workspaces.switch', $foreign))->assertForbidden();
});

it('sends users without a workspace to onboarding', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertRedirect(route('onboarding.workspace'));
    $this->get(route('knowledge.index'))->assertRedirect(route('onboarding.workspace'));
    $this->get(route('settings.members'))->assertRedirect(route('onboarding.workspace'));
});

it('renders onboarding with timezones and currencies', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('onboarding.workspace'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/CreateWorkspace', false)
            ->has('timezones')
            ->where('currencies', config('product.workspace.currencies')));
});

it('creates a workspace, makes the creator owner and audits it', function (): void {
    $user = User::factory()->create(['locale' => 'ru']);

    $this->actingAs($user)->post(route('onboarding.workspace.store'), [
        'name' => '  Демо-студия ',
        'timezone' => 'Europe/Moscow',
        'currency' => 'rub',
        'dpa' => '1',
    ])->assertRedirect(route('dashboard'))->assertSessionHas('success');

    $workspace = Workspace::query()->sole();

    expect($workspace->name)->toBe('Демо-студия')
        ->and($workspace->slug)->toBe('demo-studiia')
        ->and($workspace->currency)->toBe('RUB')
        ->and($workspace->locale)->toBe('ru')
        ->and($user->roleIn($workspace))->toBe(WorkspaceRole::Owner)
        ->and($user->refresh()->current_workspace_id)->toBe($workspace->id)
        ->and(AuditLog::query()->where('action', 'workspace.created')->where('workspace_id', $workspace->id)->exists())->toBeTrue();
});

it('validates onboarding input', function (): void {
    $this->actingAs(User::factory()->create());

    $this->post(route('onboarding.workspace.store'), ['name' => 'x', 'timezone' => 'Mars/Base', 'currency' => 'XXX'])
        ->assertSessionHasErrors(['name', 'timezone', 'currency']);
});

it('generates unique slugs', function (): void {
    Workspace::factory()->create(['slug' => 'acme']);

    expect(WorkspaceService::uniqueSlug('Acme'))->toStartWith('acme-')->not->toBe('acme');
});
