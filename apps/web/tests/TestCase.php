<?php

declare(strict_types=1);

namespace Tests;

use App\Enums\WorkspaceRole;
use App\Internal\InternalKeyring;
use App\Internal\InternalSignature;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Features;
use Symfony\Component\HttpFoundation\Response;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /** @param  array<string, bool>  $expected */
    public function assertCan(TestResponse $response, array $expected): void
    {
        $can = $response->viewData('page')['props']['can'] ?? null;

        expect($can)->toBeArray();

        foreach ($expected as $ability => $allowed) {
            expect($can)->toHaveKey($ability)
                ->and($can[$ability])->toBe($allowed, "can[{$ability}]");
        }
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /** @return array{0: User, 1: Workspace} */
    public function member(WorkspaceRole $role = WorkspaceRole::Owner, ?Workspace $workspace = null, array $attributes = []): array
    {
        $user = User::factory()->create($attributes);
        $workspace ??= Workspace::factory()->create();
        $workspace->addMember($user, $role);
        $user->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();

        return [$user->refresh(), $workspace];
    }

    /** @return array{0: User, 1: Workspace} */
    public function signInMember(WorkspaceRole $role = WorkspaceRole::Owner, ?Workspace $workspace = null): array
    {
        [$user, $workspace] = $this->member($role, $workspace);
        $this->actingAs($user);

        return [$user, $workspace];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return TestResponse<Response>
     */
    public function internal(string $method, string $uri, ?array $payload = null, string $service = 'worker', ?int $timestamp = null): TestResponse
    {
        $body = $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $keyring = app(InternalKeyring::class);
        $secret = $keyring->secretFor($service) ?? $keyring->fallbackSecret();
        $signature = (new InternalSignature($secret))->sign($method, $uri, $body, $timestamp);

        $server = $this->transformHeadersToServerVars([
            'X-Service' => $service,
            'X-Signature' => $signature,
            'X-Request-Id' => 'test-request-'.bin2hex(random_bytes(4)),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);

        return $this->call(strtoupper($method), $uri, [], [], [], $server, $body);
    }
}
