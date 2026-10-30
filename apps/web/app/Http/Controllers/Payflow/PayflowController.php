<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payflow;

use App\Authorization\Abilities;
use App\Billing\PlanLimits;
use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Flash;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

abstract class PayflowController extends Controller
{
    /**
     * @param  array<string, bool>  $page
     * @return array<string, bool>
     */
    protected function pageCan(array $page): array
    {
        $user = auth()->user();

        return [...app(Abilities::class)->map($user instanceof User ? $user : null), ...$page];
    }

    protected function workspace(): Workspace
    {
        $workspace = app(CurrentWorkspace::class)->get();
        abort_if($workspace === null, 404);

        return $workspace;
    }

    protected function role(): WorkspaceRole
    {
        $role = app(CurrentWorkspace::class)->role();
        abort_if($role === null, 403);

        return $role;
    }

    protected function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    protected function today(): string
    {
        return WorkspaceClock::todayIn($this->workspace()->timezone, app(Clock::class)->now())->toDateString();
    }

    protected function riskVisible(): bool
    {
        return app(PlanLimits::class)->hasFeature($this->workspace(), 'risk_scoring');
    }

    /** @param  array<string, mixed>  $replace */
    protected function back(string $flashKey, array $replace = []): RedirectResponse
    {
        Flash::success('payflow.flash.'.$flashKey, $replace);

        return redirect()->back(303);
    }

    /**
     * @param  array<string, mixed>|int  $parameters
     * @param  array<string, mixed>  $replace
     */
    protected function to(string $route, array|int $parameters, string $flashKey, array $replace = []): RedirectResponse
    {
        Flash::success('payflow.flash.'.$flashKey, $replace);

        return redirect()->route($route, $parameters, 303);
    }

    /** @return list<string> */
    protected static function currencies(): array
    {
        return array_keys((array) config('payflow.currencies', []));
    }
}
