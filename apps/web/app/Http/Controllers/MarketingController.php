<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Markets\Market;
use App\Billing\Markets\MarketResolver;
use App\Billing\PlanCatalog;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

final class MarketingController extends Controller
{
    public function home(Request $request, PlanCatalog $catalog, MarketResolver $markets, CurrentWorkspace $current): Response
    {
        $market = $this->market($request, $markets, $current);

        return Inertia::render('marketing/Landing', [
            'canRegister' => Features::enabled(Features::registration()),
            'plans' => $catalog->all($market->currency),
        ]);
    }

    public function pricing(Request $request, PlanCatalog $catalog, MarketResolver $markets, CurrentWorkspace $current): Response
    {
        $market = $this->market($request, $markets, $current);

        return Inertia::render('marketing/Pricing', [
            'plans' => $catalog->all($market->currency),
            'market' => ['currency' => $market->currency, 'country' => $market->country],
            'currencies' => $markets->billingCurrencies(),
        ]);
    }

    public function security(): Response
    {
        return Inertia::render('marketing/Security');
    }

    private function market(Request $request, MarketResolver $markets, CurrentWorkspace $current): Market
    {
        return $markets->forRequest($request, $request->user() !== null ? $current->get() : null);
    }
}
