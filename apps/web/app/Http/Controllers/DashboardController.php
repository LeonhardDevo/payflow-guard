<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\PlanLimits;
use App\Models\AiQuery;
use App\Models\Document;
use App\Tenancy\CurrentWorkspace;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __invoke(CurrentWorkspace $current, PlanLimits $limits): Response
    {
        $workspace = $current->get();
        abort_if($workspace === null, 404);

        $queriesThisMonth = AiQuery::query()->where('created_at', '>=', now()->startOfMonth())->count();
        $queriesLastMonth = AiQuery::query()
            ->whereBetween('created_at', [now()->subMonthNoOverflow()->startOfMonth(), now()->startOfMonth()])
            ->count();

        return Inertia::render('Dashboard', [
            'stats' => [
                [
                    'key' => 'members',
                    'label' => __('billing.limits.seats'),
                    'value' => $workspace->members()->count(),
                ],
                [
                    'key' => 'documents',
                    'label' => __('billing.limits.documents'),
                    'value' => Document::query()->count(),
                ],
                [
                    'key' => 'ai_queries',
                    'label' => __('billing.limits.ai_queries_monthly'),
                    'value' => $queriesThisMonth,
                    'delta' => $queriesLastMonth > 0
                        ? round((($queriesThisMonth - $queriesLastMonth) / $queriesLastMonth) * 100, 1)
                        : null,
                ],
                [
                    'key' => 'storage_mb',
                    'label' => __('billing.limits.storage_mb'),
                    'value' => $limits->used($workspace, 'storage_mb'),
                ],
            ],
        ]);
    }
}
