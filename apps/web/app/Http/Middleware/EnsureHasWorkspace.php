<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenancy\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureHasWorkspace
{
    public function __construct(private CurrentWorkspace $currentWorkspace) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->currentWorkspace->check()) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => __('notifications.workspace.required')], Response::HTTP_CONFLICT);
            }

            return redirect()->route('onboarding.workspace');
        }

        return $next($request);
    }
}
