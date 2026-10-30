<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\User;

it('defaults guests to English', function (): void {
    $this->get(route('home'))
        ->assertHeader('Content-Language', 'en')
        ->assertInertia(fn ($page) => $page->where('locale', 'en'));
});

it('negotiates the guest locale from Accept-Language', function (): void {
    $this->withHeader('Accept-Language', 'ru-RU,ru;q=0.9,en;q=0.8')
        ->get(route('home'))
        ->assertHeader('Content-Language', 'ru')
        ->assertInertia(fn ($page) => $page->where('locale', 'ru'));
});

it('prefers the locale cookie over Accept-Language', function (): void {
    $this->withUnencryptedCookie('locale', 'en')
        ->withHeader('Accept-Language', 'ru')
        ->get(route('home'))
        ->assertInertia(fn ($page) => $page->where('locale', 'en'));
});

it('prefers users.locale over cookie and header', function (): void {
    [$user] = $this->signInMember();
    $user->forceFill(['locale' => 'ru'])->save();

    $this->withUnencryptedCookie('locale', 'en')
        ->withHeader('Accept-Language', 'en')
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('locale', 'ru')->where('auth.user.locale', 'ru'));
});

it('switches the locale for guests via cookie and back-redirect', function (): void {
    $response = $this->from(route('pricing'))->post(route('locale.update', 'ru'));

    $response->assertRedirect(route('pricing'))->assertPlainCookie('locale', 'ru');
});

it('persists the locale on the user', function (): void {
    [$user] = $this->signInMember();

    $this->post(route('locale.update', 'ru'))->assertRedirect();

    expect($user->refresh()->locale)->toBe('ru');
});

it('rejects unsupported locales', function (): void {
    $this->post('/locale/de')->assertNotFound();
});

it('localizes validation messages', function (): void {
    $this->withHeader('Accept-Language', 'ru')
        ->post(route('register.store'), ['name' => '', 'email' => 'not-an-email', 'password' => 'x', 'password_confirmation' => 'y'])
        ->assertSessionHasErrors([
            'name' => 'Поле «имя» обязательно для заполнения.',
            'email' => 'Поле «электронная почта» должно быть действительным адресом электронной почты.',
        ]);
});

it('localizes auth failures', function (): void {
    $user = User::factory()->create();

    $this->withHeader('Accept-Language', 'ru')
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])
        ->assertSessionHasErrors(['email' => 'Неверный адрес электронной почты или пароль.']);
});

it('stores the sign-up locale on new users', function (): void {
    $this->withHeader('Accept-Language', 'ru')->post(route('register.store'), [
        'name' => 'Иван',
        'email' => 'ivan@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
        'consent_ru' => '1',
    ]);

    expect(User::query()->where('email', 'ivan@example.com')->value('locale'))->toBe('ru');
});

it('localizes pagination labels', function (): void {
    [, $workspace] = $this->signInMember();
    $this->app['auth']->user()->forceFill(['locale' => 'ru'])->save();
    Document::factory()->count(21)->create(['workspace_id' => $workspace->id]);

    $this->get(route('knowledge.index'))
        ->assertInertia(fn ($page) => $page
            ->where('documents.links.0.label', '« Назад')
            ->where('documents.meta.last_page', 2));
});

it('honours ?lang= on GET (hreflang alternates) and persists it to the cookie', function (): void {
    $this->withHeader('Accept-Language', 'en')
        ->get(route('home', ['lang' => 'ru']))
        ->assertHeader('Content-Language', 'ru')
        ->assertPlainCookie('locale', 'ru')
        ->assertInertia(fn ($page) => $page->where('locale', 'ru'));

    $this->get(route('home', ['lang' => 'de']))->assertInertia(fn ($page) => $page->where('locale', 'en'));

    [$user] = $this->signInMember();
    $this->get(route('pricing', ['lang' => 'ru']))->assertInertia(fn ($page) => $page->where('locale', 'ru'));
    expect($user->refresh()->locale)->toBe('en');
});
