<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Legal\LegalAcceptances;
use App\Models\User;
use App\Tenancy\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

final class EnsureLegalAccepted
{
    /** @var list<string> */
    private const array EXEMPT_ROUTES = [
        'legal', 'legal.index', 'legal.accept', 'legal.accept.store',
        'logout', 'locale.update', 'home', 'pricing', 'security',
        'verification.notice', 'verification.verify', 'verification.send',
        'password.confirm', 'password.confirmation', 'password.confirm.store',
        'email.opt-out', 'email.opt-out.store',
        'settings.privacy', 'settings.privacy.export', 'settings.privacy.exports.download',
        'settings.privacy.delete-account', 'settings.privacy.delete-account.cancel',
        'well-known.security', 'robots',
    ];

    public function __construct(
        private readonly LegalAcceptances $acceptances,
        private readonly CurrentWorkspace $current,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! config('legal.enforce_acceptance', true) || $this->exempt($request)) {
            return $next($request);
        }

        if ($user->email_verified_at === null) {
            return $next($request);
        }

        if ($this->acceptances->pending($user, $this->current->get(), App::getLocale()) === []) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'error' => 'legal_acceptance_required',
                'message' => __('notifications.privacy.legal_acceptance_required'),
                'redirect' => route('legal.accept'),
            ], 409);
        }

        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('legal.accept');
    }

    private function exempt(Request $request): bool
    {
        $name = $request->route()?->getName();

        return $name === null || in_array($name, self::EXEMPT_ROUTES, true) || $request->is('internal/*');
    }
}
