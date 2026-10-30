<?php

declare(strict_types=1);

namespace App\Audit;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;

final class AuditMaintenance
{
    public const string PSEUDONYM_PREFIX = 'deleted-user-';

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(Closure $callback): mixed
    {
        return DB::transaction(function () use ($callback): mixed {
            DB::select("SELECT set_config('app.audit_maintenance', 'on', true)");

            try {
                return $callback();
            } finally {
                DB::select("SELECT set_config('app.audit_maintenance', 'off', true)");
            }
        });
    }

    public static function pseudonym(int $userId): string
    {
        return self::PSEUDONYM_PREFIX.substr(hash_hmac('sha256', 'user:'.$userId, (string) config('app.key')), 0, 12);
    }

    public static function pseudonymizeUser(int $userId): int
    {
        $pseudonym = self::pseudonym($userId);

        return self::run(function () use ($userId, $pseudonym): int {
            $actor = DB::table('audit_logs')
                ->where('actor_id', $userId)
                ->update(['actor_id' => null, 'actor_name' => $pseudonym, 'ip' => null, 'user_agent' => null]);

            $subject = DB::table('audit_logs')
                ->where('subject_type', 'user')
                ->where('subject_id', (string) $userId)
                ->update(['subject_label' => $pseudonym, 'metadata' => null]);

            return $actor + $subject;
        });
    }

    public static function purgeOlderThan(?int $workspaceId, CarbonInterface $cutoff): int
    {
        return self::run(fn (): int => DB::table('audit_logs')
            ->when(
                $workspaceId === null,
                fn ($query) => $query->whereNull('workspace_id'),
                fn ($query) => $query->where('workspace_id', $workspaceId),
            )
            ->where('created_at', '<', $cutoff)
            ->delete());
    }
}
