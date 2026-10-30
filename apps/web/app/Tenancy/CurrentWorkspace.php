<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

final class CurrentWorkspace
{
    public const string SESSION_KEY = 'current_workspace_id';

    private ?string $resolvedKey = null;

    private ?Workspace $workspace = null;

    private ?WorkspaceRole $role = null;

    private ?Workspace $override = null;

    private ?WorkspaceRole $overrideRole = null;

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Container $container,
    ) {}

    private function request(): Request
    {
        /** @var Request */
        return $this->container->make('request');
    }

    public function get(): ?Workspace
    {
        if ($this->override !== null) {
            return $this->override;
        }

        $this->resolve();

        return $this->workspace;
    }

    public function id(): ?int
    {
        return $this->get()?->id;
    }

    public function role(): ?WorkspaceRole
    {
        if ($this->override !== null) {
            return $this->overrideRole;
        }

        $this->resolve();

        return $this->role;
    }

    public function check(): bool
    {
        return $this->get() !== null;
    }

    public function allows(string $ability): bool
    {
        return $this->role()?->allows($ability) ?? false;
    }

    public function switchTo(User $user, Workspace $workspace): void
    {
        if (! $user->belongsToWorkspace($workspace)) {
            throw new \DomainException('User is not a member of this workspace.');
        }

        if ($this->request()->hasSession()) {
            $this->request()->session()->put(self::SESSION_KEY, $workspace->id);
        }

        if ($user->current_workspace_id !== $workspace->id) {
            $user->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();
        }

        $this->forget();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(Workspace $workspace, Closure $callback, ?WorkspaceRole $role = null): mixed
    {
        [$previous, $previousRole] = [$this->override, $this->overrideRole];
        $this->override = $workspace;
        $this->overrideRole = $role;

        try {
            return $callback();
        } finally {
            $this->override = $previous;
            $this->overrideRole = $previousRole;
        }
    }

    public function forget(): void
    {
        $this->resolvedKey = null;
        $this->workspace = null;
        $this->role = null;
    }

    private function resolve(): void
    {
        $user = $this->auth->guard()->user();

        if (! $user instanceof User) {
            $this->resolvedKey = null;
            $this->workspace = null;
            $this->role = null;

            return;
        }

        $sessionId = $this->request()->hasSession()
            ? $this->request()->session()->get(self::SESSION_KEY)
            : null;

        $key = spl_object_id($this->request()).'|'.$user->id.'|'.(is_scalar($sessionId) ? (string) $sessionId : '').'|'.($user->current_workspace_id ?? '');

        if ($key === $this->resolvedKey) {
            return;
        }

        $this->resolvedKey = $key;
        $this->workspace = null;
        $this->role = null;

        $candidates = array_values(array_filter([
            is_numeric($sessionId) ? (int) $sessionId : null,
            $user->current_workspace_id,
        ]));

        foreach ($candidates as $candidateId) {
            $workspace = $user->workspaces()->whereKey($candidateId)->first();

            if ($workspace !== null) {
                $this->select($workspace);

                return;
            }
        }

        $first = $user->workspaces()->orderBy('workspace_user.created_at')->orderBy('workspaces.id')->first();

        if ($first !== null) {
            $this->select($first);
        }
    }

    private function select(Workspace $workspace): void
    {
        $this->workspace = $workspace;
        $role = $workspace->membership?->role;
        $this->role = $role instanceof WorkspaceRole ? $role : WorkspaceRole::tryFrom((string) $role);
    }
}
