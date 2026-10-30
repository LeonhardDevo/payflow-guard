<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal\Payflow;

use App\Domain\Analytics\Risk\RiskRecomputer;
use App\Domain\Shared\Time\Clock;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WorkspacesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Workspace::query()->orderBy('id');

        if ($request->boolean('active', true)) {
            $query->where(fn ($inner) => $inner
                ->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('invoices')->whereColumn('invoices.workspace_id', 'workspaces.id')
                    ->whereIn('invoices.status', ['open', 'partially_paid', 'disputed'])->whereNull('invoices.deleted_at'))
                ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))->from('vendors')->whereColumn('vendors.workspace_id', 'workspaces.id')->whereNull('vendors.deleted_at'))
                ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))->from('payflow_settings')->whereColumn('payflow_settings.workspace_id', 'workspaces.id')->where('payflow_settings.digest_enabled', true)));
        }

        return response()->json([
            'data' => $query->get(['id', 'timezone'])->map(fn (Workspace $workspace): array => ['id' => $workspace->id, 'timezone' => $workspace->timezone])->values()->all(),
        ]);
    }

    public function recomputeRisk(Request $request, int $workspace, RiskRecomputer $risk, Clock $clock): JsonResponse
    {
        $model = Workspace::query()->find($workspace);

        if ($model === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $data = $request->validate(['client_id' => ['nullable', 'integer']]);
        $started = microtime(true);
        $result = $risk->workspace($model, $clock->now(), isset($data['client_id']) ? (int) $data['client_id'] : null);

        return response()->json([...$result, 'duration_ms' => (int) round((microtime(true) - $started) * 1000)]);
    }
}
