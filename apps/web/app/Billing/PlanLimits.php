<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\AiQuery;
use App\Models\Document;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;

final readonly class PlanLimits
{
    public function __construct(
        private PlanCatalog $catalog,
        private PlanLimitResolvers $registered,
    ) {}

    /** @param  \Closure(Workspace): int  $resolver */
    public function registerResolver(string $key, \Closure $resolver): void
    {
        $this->registered->register($key, $resolver);
    }

    public function hasFeature(Workspace $workspace, string $entitlement): bool
    {
        return in_array($entitlement, $this->catalog->entitlements($workspace->planKey()), true);
    }

    public function hasResolver(string $key): bool
    {
        return $this->registered->has($key) || array_key_exists($key, $this->usageResolvers());
    }

    public function limit(Workspace $workspace, string $key): ?int
    {
        return $this->catalog->limit($workspace->planKey(), $key);
    }

    public function used(Workspace $workspace, string $key): int
    {
        $resolver = $this->registered->get($key) ?? $this->usageResolvers()[$key] ?? null;

        if ($resolver === null) {
            throw new \InvalidArgumentException("No usage resolver for plan limit [{$key}].");
        }

        return $resolver($workspace);
    }

    public function allows(Workspace $workspace, string $key, int $adding = 1): bool
    {
        $limit = $this->limit($workspace, $key);

        return $limit === null || $this->used($workspace, $key) + $adding <= $limit;
    }

    /** @throws PlanLimitExceeded */
    public function ensure(Workspace $workspace, string $key, int $adding = 1): void
    {
        if (! $this->allows($workspace, $key, $adding)) {
            throw new PlanLimitExceeded($key, (int) $this->limit($workspace, $key));
        }
    }

    /** @return array<string, array{used: int, limit: int|null}> */
    public function usage(Workspace $workspace): array
    {
        $usage = [];

        foreach (array_keys($this->catalog->limits($workspace->planKey())) as $key) {
            if (! $this->hasResolver($key)) {
                continue;
            }

            $usage[$key] = ['used' => $this->used($workspace, $key), 'limit' => $this->limit($workspace, $key)];
        }

        return $usage;
    }

    /** @return array<string, \Closure(Workspace): int> */
    private function usageResolvers(): array
    {
        return [
            'seats' => fn (Workspace $workspace): int => $workspace->members()->count()
                + WorkspaceInvitation::query()->where('workspace_id', $workspace->id)->pending()->count(),
            'documents' => fn (Workspace $workspace): int => Document::query()->forWorkspace($workspace)->count(),
            'ai_queries_monthly' => fn (Workspace $workspace): int => AiQuery::query()
                ->forWorkspace($workspace)
                ->where('created_at', '>=', now()->startOfMonth())
                ->count(),
            'storage_mb' => fn (Workspace $workspace): int => (int) ceil(
                ((int) Document::query()->forWorkspace($workspace)->sum('size_bytes')) / 1048576
            ),
        ];
    }
}
