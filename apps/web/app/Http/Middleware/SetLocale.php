<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Locale\Locales;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class SetLocale
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = self::resolve($request);

        if (self::fromQuery($request) !== null && $request->cookie(Locales::COOKIE) !== $locale) {
            Cookie::queue(Cookie::make(Locales::COOKIE, $locale, minutes: 60 * 24 * 365, httpOnly: false, sameSite: 'lax'));
        }

        App::setLocale($locale);
        Carbon::setLocale($locale);
        CarbonImmutable::setLocale($locale);

        $response = $next($request);

        if (! $response->headers->has('Content-Language')) {
            $response->headers->set('Content-Language', $locale);
        }

        return $response;
    }

    public static function resolve(Request $request): string
    {
        $query = self::fromQuery($request);

        if ($query !== null) {
            return $query;
        }

        $user = $request->user();

        if ($user instanceof User && Locales::isSupported($user->locale)) {
            return $user->locale;
        }

        $cookie = $request->cookie(Locales::COOKIE);

        if (is_string($cookie) && Locales::isSupported($cookie)) {
            return $cookie;
        }

        return Locales::negotiate($request->header('Accept-Language')) ?? Locales::fallback();
    }

    private static function fromQuery(Request $request): ?string
    {
        if (! $request->isMethodSafe()) {
            return null;
        }

        $lang = $request->query('lang');

        return is_string($lang) && Locales::isSupported($lang) ? $lang : null;
    }
}
