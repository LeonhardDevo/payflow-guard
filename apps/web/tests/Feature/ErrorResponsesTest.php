<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;

it('turns a throttled Inertia request into a localized flash error instead of a raw page', function (): void {
    [$user] = $this->signInMember(WorkspaceRole::Owner);
    $user->forceFill(['locale' => 'ru'])->save();
    app()->setLocale('ru');

    $headers = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Referer' => route('settings.billing')];

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->post(route('settings.billing.cancel'), [], $headers);
    }

    $this->post(route('settings.billing.cancel'), [], $headers)
        ->assertStatus(303)
        ->assertRedirect(route('settings.billing'))
        ->assertSessionHas('error', fn (string $message): bool => str_starts_with($message, 'Слишком много попыток'));
});

it('keeps JSON error responses for the internal API', function (): void {
    $this->postJson('/internal/v1/payments/reconcile')->assertUnauthorized();
});
