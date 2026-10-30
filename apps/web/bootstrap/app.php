<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureFakeBilling;
use App\Http\Middleware\EnsureHasWorkspace;
use App\Http\Middleware\EnsureInternalCaller;
use App\Http\Middleware\EnsureLegalAccepted;
use App\Http\Middleware\EnsureWithinPlanLimit;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\VerifyInternalSignature;
use App\Support\Flash;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('internal')
                ->prefix('internal/v1')
                ->name('internal.')
                ->group(base_path('routes/internal.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'locale']);

        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            SetLocale::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsureLegalAccepted::class,
        ]);

        $middleware->validateCsrfTokens(except: ['email/opt-out/*', 'i/*/reminders/one-click']);

        $middleware->group('internal', [
            VerifyInternalSignature::class,
            'throttle:internal',
            SubstituteBindings::class,
        ]);

        $middleware->alias([
            'workspace' => EnsureHasWorkspace::class,
            'plan.limit' => EnsureWithinPlanLimit::class,
            'fake.billing' => EnsureFakeBilling::class,
            'internal.caller' => EnsureInternalCaller::class,
        ]);

        $middleware->redirectUsersTo(fn (): string => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'internal/*') || $request->expectsJson(),
        );

        $exceptions->dontFlash([
            'current_password', 'password', 'password_confirmation', 'api_key', 'token',
            'iban', 'account_number', 'routing_number', 'change_request_text',
            'bank.iban', 'bank.account_number', 'bank.routing_number',
        ]);

        $exceptions->respond(function (SymfonyResponse $response, Throwable $exception, Request $request): SymfonyResponse {
            $status = $response->getStatusCode();

            if (! in_array($status, [419, 429, 503], true) || $request->header('X-Inertia') === null || $request->isMethodSafe() || $request->is('api/*', 'internal/*')) {
                return $response;
            }

            $retryAfter = (int) ($response->headers->get('Retry-After') ?? 60);
            Flash::errorMessage((string) __('errors.http.'.$status, ['seconds' => max(1, $retryAfter)]));

            return redirect()->back(303);
        });
    })->create();
