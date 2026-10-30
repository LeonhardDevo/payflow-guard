<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Legal\LegalAcceptances;
use App\Legal\LegalRepository;
use App\Models\AuditLog;
use App\Models\LegalAcceptance;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\File;

function registrationPayload(array $extra = []): array
{
    return [
        'name' => 'Test User',
        'email' => 'legal@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        ...$extra,
    ];
}

function acceptAll(User $user, ?Workspace $workspace = null): void
{
    $acceptances = app(LegalAcceptances::class);
    $acceptances->acceptRegistration($user, $user->locale, null);

    if ($workspace !== null) {
        $acceptances->acceptWorkspace($user, $workspace, $user->locale, null);
    }
}

it('passes the registration documents to the Register page', function (): void {
    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/Register', false)
            ->where('legal.terms.version', '2.0')
            ->where('legal.terms.url', route('legal', 'terms'))
            ->where('legal.privacy.version', '1.0')
            ->where('legal.consent_ru', null)
            ->etc());

    $this->withHeader('Accept-Language', 'ru')->get(route('register'))
        ->assertInertia(fn ($page) => $page->where('legal.consent_ru.version', '1.0')->etc());
});

it('requires the unchecked terms checkbox at registration', function (): void {
    $this->post(route('register.store'), registrationPayload())->assertSessionHasErrors('terms');
    $this->post(route('register.store'), registrationPayload(['terms' => '0']))->assertSessionHasErrors('terms');

    expect(User::query()->count())->toBe(0);
});

it('records terms and privacy acceptance with ip, user agent and locale (en)', function (): void {
    $this->withHeader('User-Agent', 'LegalTest/1.0')
        ->post(route('register.store'), registrationPayload(['terms' => '1']))
        ->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'legal@example.com')->sole();
    $rows = LegalAcceptance::query()->where('user_id', $user->id)->orderBy('document')->get();

    expect($rows->pluck('document')->all())->toBe(['privacy', 'terms'])
        ->and($rows->firstWhere('document', 'terms')->version)->toBe('2.0')
        ->and($rows->first()->locale)->toBe('en')
        ->and($rows->first()->ip)->toBe('127.0.0.1')
        ->and($rows->first()->user_agent)->toBe('LegalTest/1.0')
        ->and($rows->first()->workspace_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'legal.accepted')->count())->toBe(2);
});

it('requires and records the 152-FZ consent for Russian sign-ups', function (): void {
    $this->withHeader('Accept-Language', 'ru')
        ->post(route('register.store'), registrationPayload(['terms' => '1']))
        ->assertSessionHasErrors('consent_ru');

    $this->withHeader('Accept-Language', 'ru')
        ->post(route('register.store'), registrationPayload(['terms' => '1', 'consent_ru' => '1']))
        ->assertSessionHasNoErrors();

    $user = User::query()->sole();

    expect($user->locale)->toBe('ru')
        ->and(LegalAcceptance::query()->where('user_id', $user->id)->orderBy('document')->pluck('document')->all())
        ->toBe(['consent-ru', 'privacy', 'terms']);
});

it('requires the DPA when creating a workspace and records it for the workspace', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('onboarding.workspace'))
        ->assertInertia(fn ($page) => $page->where('dpa.version', '1.0')->where('dpa.url', route('legal', 'dpa'))->etc());

    $payload = ['name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD'];

    $this->post(route('onboarding.workspace.store'), $payload)->assertSessionHasErrors('dpa');
    expect(Workspace::query()->count())->toBe(0);

    $this->post(route('onboarding.workspace.store'), [...$payload, 'dpa' => '1'])->assertRedirect(route('dashboard'));

    $workspace = Workspace::query()->sole();
    $acceptance = LegalAcceptance::query()->where('document', 'dpa')->sole();

    expect($acceptance->workspace_id)->toBe($workspace->id)
        ->and($acceptance->user_id)->toBe($user->id)
        ->and($acceptance->version)->toBe('1.0');
});

describe('re-acceptance', function (): void {
    beforeEach(fn () => config(['legal.enforce_acceptance' => true]));

    it('blocks a user who accepted an older version that requires re-acceptance', function (): void {
        [$user, $workspace] = $this->signInMember(WorkspaceRole::Owner);
        acceptAll($user, $workspace);
        LegalAcceptance::query()->where('document', 'terms')->update(['version' => '1.0']);

        $this->get(route('dashboard'))->assertRedirect(route('legal.accept'));

        $this->get(route('legal.accept'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('legal/Accept', false)
                ->has('pending', 1)
                ->where('pending.0.key', 'terms')
                ->where('pending.0.version', '2.0')
                ->where('pending.0.summary_of_changes', 'Fixture change summary')
                ->etc());

        $this->postJson(route('assistant.ask'), ['message' => 'hi'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'legal_acceptance_required');

        $this->get(route('settings.privacy'))->assertOk();
        $this->get(route('legal', 'terms'))->assertOk();

        $this->post(route('legal.accept.store'), [])->assertSessionHasErrors('accept');
        $this->post(route('legal.accept.store'), ['accept' => '1'])->assertRedirect(route('dashboard'));

        expect(LegalAcceptance::query()->where('document', 'terms')->where('version', '2.0')->exists())->toBeTrue();
        $this->get(route('dashboard'))->assertOk();
    });

    it('asks workspace owners to accept the DPA, but not members', function (): void {
        [$owner, $workspace] = $this->signInMember(WorkspaceRole::Owner);
        acceptAll($owner);

        $this->get(route('dashboard'))->assertRedirect(route('legal.accept'));
        $this->get(route('legal.accept'))->assertInertia(fn ($page) => $page->where('pending.0.key', 'dpa')->where('pending.0.workspace', $workspace->name)->etc());
        $this->post(route('legal.accept.store'), ['accept' => '1']);

        expect(LegalAcceptance::query()->where('document', 'dpa')->where('workspace_id', $workspace->id)->exists())->toBeTrue();

        [$member] = $this->member(WorkspaceRole::Member, $workspace);
        acceptAll($member);
        $this->actingAs($member)->get(route('dashboard'))->assertOk();
    });

    it('does not block for a new version that does not require acceptance', function (): void {
        $path = storage_path('framework/testing/legal-reaccept');
        File::copyDirectory(base_path('tests/Fixtures/legal'), $path);
        config(['legal.path' => $path]);
        app()->forgetInstance(LegalRepository::class);

        [$user, $workspace] = $this->signInMember(WorkspaceRole::Owner);
        acceptAll($user, $workspace);

        File::put($path.'/en/privacy.md', "---\ntitle: Fixture Privacy\nversion: \"1.1\"\neffective_date: 2026-11-01\nrequires_acceptance: false\n---\n## Typo fix\n");
        app()->forgetInstance(LegalRepository::class);

        $this->get(route('dashboard'))->assertOk();

        File::deleteDirectory($path);
    });

    it('keeps asking for a re-accepted ru consent after the user switches to en', function (): void {
        [$user, $workspace] = $this->signInMember(WorkspaceRole::Owner);
        $user->forceFill(['locale' => 'ru'])->save();
        acceptAll($user, $workspace);
        expect(LegalAcceptance::query()->where('user_id', $user->id)->where('document', 'consent-ru')->exists())->toBeTrue();

        LegalAcceptance::query()->where('document', 'consent-ru')->update(['version' => '0.9']);
        $user->forceFill(['locale' => 'en'])->save();

        $this->get(route('dashboard'))->assertRedirect(route('legal.accept'));
        $this->get(route('legal.accept'))->assertInertia(fn ($page) => $page->has('pending', 1)->where('pending.0.key', 'consent-ru')->etc());
    });

    it('does not ask an existing en user for the ru consent after switching the UI to ru', function (): void {
        [$user, $workspace] = $this->signInMember(WorkspaceRole::Owner);
        acceptAll($user, $workspace);
        $user->forceFill(['locale' => 'ru'])->save();

        $this->get(route('dashboard'))->assertOk();
    });

    it('lets users without any acceptance (pre-F2 accounts) accept everything', function (): void {
        $this->signInMember(WorkspaceRole::Member);

        $this->get(route('dashboard'))->assertRedirect(route('legal.accept'));
        $this->get(route('legal.accept'))->assertInertia(fn ($page) => $page->has('pending', 2)->etc());
    });
});

it('lists the acceptance history in Settings → Privacy', function (): void {
    [$user, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    acceptAll($user, $workspace);

    $this->get(route('settings.privacy'))
        ->assertInertia(fn ($page) => $page->has('acceptances', 3)
            ->has('acceptances.0', fn ($row) => $row->hasAll(['document', 'version', 'accepted_at']))
            ->etc());
});
