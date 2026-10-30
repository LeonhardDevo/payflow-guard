<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Billing\PlanLimits;
use App\Domain\Analytics\ReceivablesAnalytics;
use App\Domain\Shared\Time\Clock;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AnalyticsController extends PayflowController
{
    public function __invoke(Request $request, Clock $clock, PlanLimits $limits): Response
    {
        $workspace = $this->workspace();
        $analytics = new ReceivablesAnalytics($workspace, $clock->now());
        $currency = $analytics->resolveCurrency(is_string($request->query('currency')) ? $request->query('currency') : null);
        $months = in_array((int) $request->query('months', 6), [3, 6, 12], true) ? (int) $request->query('months', 6) : 6;
        $advanced = $limits->hasFeature($workspace, 'analytics_advanced');
        $risk = $limits->hasFeature($workspace, 'risk_scoring');
        $locked = [];

        if (! $advanced) {
            $locked[] = 'forecast';
            $locked[] = 'tone_effectiveness';
        }

        if (! $risk) {
            $locked[] = 'risk_mix';
        }

        return Inertia::render('analytics/Index', [
            'currency' => $currency,
            'currencies' => $analytics->currencies(),
            'months' => $months,
            'kpis' => $analytics->kpis($currency, $risk),
            'aging' => $analytics->aging($currency),
            'monthly' => $analytics->monthly($currency, $months),
            'dso_trend' => $analytics->dsoTrend($currency, $months),
            'forecast' => $advanced ? $analytics->forecast($currency) : null,
            'late_payers' => $analytics->latePayers($currency, 10),
            'risk_mix' => $risk ? $analytics->riskMix($currency) : null,
            'tone_effectiveness' => $advanced ? $analytics->toneEffectiveness() : null,
            'locked' => $locked,
        ]);
    }
}
