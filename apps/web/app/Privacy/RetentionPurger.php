<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Audit\AuditMaintenance;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class RetentionPurger
{
    public function __construct(
        private RetentionPolicy $policy,
        private DataExports $exports,
    ) {}

    /** @return array<string, int> rows (or files) removed per category */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return [
            'ai_queries' => $this->purgePerWorkspace('ai_queries', $now, fn (array $ids, CarbonImmutable $cutoff): int => DB::table('ai_queries')
                ->whereIn('workspace_id', $ids)
                ->where('created_at', '<', $cutoff)
                ->delete()),
            'audit_logs' => $this->purgeAuditLogs($now),
            'deliveries' => DB::table('deliveries')
                ->where('created_at', '<', $now->subDays($this->policy->default('deliveries')))
                ->delete(),
            'outbox_dispatched' => DB::table('outbox_messages')
                ->whereNotNull('dispatched_at')
                ->where('dispatched_at', '<', $now->subDays($this->policy->default('outbox_dispatched')))
                ->delete(),
            'failed_jobs' => DB::table('failed_jobs')
                ->where('failed_at', '<', $now->subDays($this->policy->default('failed_jobs')))
                ->delete(),
            'notifications' => DB::table('notifications')
                ->where('created_at', '<', $now->subDays($this->policy->default('notifications')))
                ->delete(),
            'data_exports' => $this->exports->purgeExpired(),
        ];
    }

    private function purgeAuditLogs(CarbonImmutable $now): int
    {
        $count = $this->purgePerWorkspace('audit_logs', $now, fn (array $ids, CarbonImmutable $cutoff): int => AuditMaintenance::run(
            fn (): int => DB::table('audit_logs')
                ->whereIn('workspace_id', $ids)
                ->where('created_at', '<', $cutoff)
                ->delete(),
        ));

        $cutoff = $now->subDays($this->policy->default('audit_logs'));

        $count += AuditMaintenance::purgeOlderThan(null, $cutoff);
        $count += AuditMaintenance::run(fn (): int => DB::table('audit_logs')
            ->whereNotNull('workspace_id')
            ->whereNotIn('workspace_id', DB::table('workspaces')->select('id'))
            ->where('created_at', '<', $cutoff)
            ->delete());

        return $count;
    }

    /** @param  \Closure(list<int>, CarbonImmutable): int  $purge */
    private function purgePerWorkspace(string $key, CarbonImmutable $now, \Closure $purge): int
    {
        /** @var array<int, list<int>> $groups */
        $groups = [];

        Workspace::query()->with('subscription')->select(['id', 'retention'])->chunkById(500, function ($workspaces) use (&$groups, $key): void {
            foreach ($workspaces as $workspace) {
                $groups[$this->policy->days($key, $workspace)][] = $workspace->id;
            }
        });

        $count = 0;

        foreach ($groups as $days => $ids) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                $count += $purge($chunk, $now->subDays($days));
            }
        }

        return $count;
    }
}
