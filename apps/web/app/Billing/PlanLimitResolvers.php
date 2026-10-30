<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\Workspace;
use Closure;

final class PlanLimitResolvers
{
    /** @var array<string, Closure(Workspace): int> */
    private array $resolvers = [];

    /** @param  Closure(Workspace): int  $resolver */
    public function register(string $key, Closure $resolver): void
    {
        $this->resolvers[$key] = $resolver;
    }

    /** @return Closure(Workspace): int|null */
    public function get(string $key): ?Closure
    {
        return $this->resolvers[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->resolvers[$key]);
    }
}
