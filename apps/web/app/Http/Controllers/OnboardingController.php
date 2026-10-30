<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Markets\MarketResolver;
use App\Http\Requests\Workspaces\StoreWorkspaceRequest;
use App\Legal\LegalAcceptances;
use App\Models\User;
use App\Support\Flash;
use App\Support\Options;
use App\Tenancy\CurrentWorkspace;
use App\Workspaces\WorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class OnboardingController extends Controller
{
    public function create(Request $request, CurrentWorkspace $current, LegalAcceptances $acceptances, MarketResolver $markets): Response|RedirectResponse
    {
        if ($current->check() && ! $request->boolean('new')) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('onboarding/CreateWorkspace', [
            'timezones' => Options::timezones(),
            'currencies' => Options::currencies(),
            'countries' => $markets->countryOptions(),
            'defaultCountry' => $markets->forRequest($request)->country,
            'dpa' => $acceptances->workspaceProp(App::getLocale()),
        ]);
    }

    public function store(StoreWorkspaceRequest $request, WorkspaceService $workspaces, LegalAcceptances $acceptances): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{name: string, timezone: string, currency: string, country: string, locale?: string|null} $data */
        $data = $request->safe()->except(['dpa']);
        $workspace = DB::transaction(function () use ($workspaces, $user, $data, $acceptances, $request) {
            $workspace = $workspaces->create($user, $data);
            $acceptances->acceptWorkspace($user, $workspace, App::getLocale(), $request);

            return $workspace;
        });

        Flash::success('notifications.workspace.created', ['name' => $workspace->name]);

        return redirect()->route('dashboard');
    }
}
