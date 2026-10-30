<?php

declare(strict_types=1);

namespace App\Http\Middleware\Payflow;

use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\FrozenClock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ApplyClockOverride
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $now = $request->input('now', $request->query('now'));

        if (config('payflow.allow_clock_override') === true && ! app()->isProduction() && is_string($now) && $now !== '') {
            try {
                app()->instance(Clock::class, new FrozenClock($now));
            } catch (Throwable) {
                return response()->json(['error' => 'invalid_now'], 422);
            }
        }

        return $next($request);
    }
}
