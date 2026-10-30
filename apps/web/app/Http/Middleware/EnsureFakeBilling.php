<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureFakeBilling
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('billing.driver') === 'fake', 404);
        abort_if(app()->isProduction() && ! config('billing.allow_fake_in_production'), 404);

        return $next($request);
    }
}
