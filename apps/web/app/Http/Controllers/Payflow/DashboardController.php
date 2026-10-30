<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Billing\PlanLimits;
use App\Domain\Analytics\DashboardBuilder;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Time\Clock;
use App\Http\Controllers\DashboardController as FoundationDashboardController;
use App\Models\AiQuery;
use App\Models\Document;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends PayflowController
{
    public function __invoke(Request $request, DashboardBuilder $builder, Clock $clock, CurrentWorkspace $current, PlanLimits $limits): Response
    {
        $role = $this->role();

        if (! $role->allows('analytics.view')) {
            return (new FoundationDashboardController)($current, $limits);
        }

        $currency = is_string($request->query('currency')) ? $request->query('currency') : null;
        $workspace = $this->workspace();

        return Inertia::render('Dashboard', [
            ...$builder->build($workspace, $this->user($request), $role, $currency, $clock->now()),
            'stats' => $this->workspaceStats($workspace, $limits),
        ]);
    }

    /** @return list<array{key: string, label: string, value: int, delta?: float|null}> */
    private function workspaceStats(Workspace $workspace, PlanLimits $limits): array
    {
        $thisMonth = AiQuery::query()->where('created_at', '>=', now()->startOfMonth())->count();
        $lastMonth = AiQuery::query()->whereBetween('created_at', [now()->subMonthNoOverflow()->startOfMonth(), now()->startOfMonth()])->count();

        return [
            ['key' => 'members', 'label' => (string) __('billing.limits.seats'), 'value' => $workspace->members()->count()],
            ['key' => 'documents', 'label' => (string) __('billing.limits.documents'), 'value' => Document::query()->count()],
            [
                'key' => 'ai_queries',
                'label' => (string) __('billing.limits.ai_queries_monthly'),
                'value' => $thisMonth,
                'delta' => $lastMonth > 0 ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1) : null,
            ],
            ['key' => 'storage_mb', 'label' => (string) __('billing.limits.storage_mb'), 'value' => $limits->used($workspace, 'storage_mb')],
        ];
    }

    public function dismissOnboarding(Request $request): RedirectResponse
    {
        $workspace = $this->workspace();
        WorkspaceProvisioner::settingsFor($workspace)->forceFill(['onboarding_dismissed' => true])->save();
        Cache::forget('pf-shared:'.$workspace->id.':'.$this->user($request)->id);

        return redirect()->back(303);
    }
}
