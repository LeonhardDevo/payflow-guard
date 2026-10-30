<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Billing\PlanLimitExceeded;
use App\Billing\PlanLimits;
use App\Support\Flash;
use App\Tenancy\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureWithinPlanLimit
{
    public function __construct(
        private PlanLimits $limits,
        private CurrentWorkspace $currentWorkspace,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, string $limitKey): Response
    {
        $workspace = $this->currentWorkspace->get();

        if ($workspace !== null) {
            try {
                $this->limits->ensure($workspace, $limitKey);
            } catch (PlanLimitExceeded $exception) {
                if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                    return response()->json([
                        'message' => $exception->userMessage(),
                        'limit' => $exception->limitKey,
                        'upgrade' => route('settings.billing'),
                    ], Response::HTTP_PAYMENT_REQUIRED);
                }

                Flash::errorMessage($exception->userMessage());

                return back();
            }
        }

        return $next($request);
    }
}
