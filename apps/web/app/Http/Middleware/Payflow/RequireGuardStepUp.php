<?php

declare(strict_types=1);

namespace App\Http\Middleware\Payflow;

use App\Domain\Guard\StepUp\StepUpGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireGuardStepUp
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        StepUpGate::require($request);

        return $next($request);
    }
}
