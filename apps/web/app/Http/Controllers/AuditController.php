<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\Paginated;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Inertia\Inertia;
use Inertia\Response;

final class AuditController extends Controller
{
    public function index(Request $request, CurrentWorkspace $current): Response
    {
        $this->authorize('audit.view');
        $workspaceId = $current->id();
        abort_if($workspaceId === null, 404);

        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:120'],
            'actor' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $timezone = $current->get()->timezone ?? 'UTC';

        $logs = AuditLog::query()
            ->where('workspace_id', $workspaceId)
            ->when($filters['action'] ?? null, fn (Builder $query, string $action) => str_ends_with($action, '.*')
                ? $query->where('action', 'like', addcslashes(substr($action, 0, -1), '%_\\').'%')
                : $query->where('action', $action))
            ->when($filters['actor'] ?? null, fn (Builder $query, int|string $actor) => $query->where('actor_id', (int) $actor))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where(
                'created_at',
                '>=',
                Carbon::createFromFormat('Y-m-d', $from, $timezone)?->startOfDay()->utc(),
            ))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where(
                'created_at',
                '<=',
                Carbon::createFromFormat('Y-m-d', $to, $timezone)?->endOfDay()->utc(),
            ))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $actions = AuditLog::query()
            ->where('workspace_id', $workspaceId)
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->all();

        return Inertia::render('audit/Index', [
            'logs' => Paginated::from($logs, fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'action_label' => self::actionLabel($log->action),
                'actor' => $log->actor_id !== null ? ['id' => $log->actor_id, 'name' => (string) $log->actor_name] : null,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'subject_label' => $log->subject_label,
                'changes' => $log->changes,
                'ip' => $log->ip,
                'created_at' => $log->created_at->toIso8601String(),
            ]),
            'filters' => array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== ''),
            'actions' => $actions,
            'actionLabels' => array_combine($actions, array_map(self::actionLabel(...), $actions)) ?: (object) [],
            'actors' => AuditLog::query()
                ->where('workspace_id', $workspaceId)
                ->whereNotNull('actor_id')
                ->select(['actor_id', 'actor_name'])
                ->distinct()
                ->orderBy('actor_name')
                ->get()
                ->map(fn (AuditLog $log): array => ['id' => (int) $log->actor_id, 'name' => (string) $log->actor_name])
                ->unique('id')
                ->values()
                ->all(),
        ]);
    }

    private static function actionLabel(string $action): string
    {
        $key = 'audit.actions.'.$action;

        return Lang::has($key) ? (string) __($key) : $action;
    }
}
