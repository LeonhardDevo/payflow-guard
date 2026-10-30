<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Audit\Auditor;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;

final readonly class PrivacyPurge
{
    private const string LOCK = 'privacy:purge';

    public function __construct(
        private DeletionRequests $deletions,
        private RetentionPurger $retention,
        private RetentionPolicy $policy,
        private Auditor $auditor,
    ) {}

    /** @return array{deletions: array{users: int, workspaces: int, blocked: int, failed: int}, retention: array<string, int>, webhook_events_days: int, ai_memory?: array{default_days: int, workspaces: list<array{workspace_id: int, days: int}>}, skipped: bool} */
    public function run(): array
    {
        $lock = Cache::lock(self::LOCK, 3600);

        if (! $lock->get()) {
            return [
                'deletions' => ['users' => 0, 'workspaces' => 0, 'blocked' => 0, 'failed' => 0],
                'retention' => [],
                'webhook_events_days' => $this->policy->webhookEventsDays(),
                'skipped' => true,
            ];
        }

        try {
            $deletions = $this->deletions->runDue();
            $retention = $this->retention->run();

            $this->auditor->record(
                'privacy.retention_purged',
                metadata: ['deletions' => $deletions, 'retention' => $retention],
                actor: null,
                workspace: null,
                platform: true,
                system: true,
            );

            return [
                'deletions' => $deletions,
                'retention' => $retention,
                'webhook_events_days' => $this->policy->webhookEventsDays(),
                'ai_memory' => $this->aiMemory(),
                'skipped' => false,
            ];
        } finally {
            $lock->release();
        }
    }

    /** @return array{default_days: int, workspaces: list<array{workspace_id: int, days: int}>} */
    private function aiMemory(): array
    {
        $default = $this->policy->days('ai_queries');
        $workspaces = [];

        Workspace::query()->with('subscription')->select(['id', 'retention'])->chunkById(500, function ($chunk) use ($default, &$workspaces): void {
            foreach ($chunk as $workspace) {
                $days = $this->policy->days('ai_queries', $workspace);

                if ($days !== $default) {
                    $workspaces[] = ['workspace_id' => $workspace->id, 'days' => $days];
                }
            }
        });

        return ['default_days' => $default, 'workspaces' => $workspaces];
    }
}
