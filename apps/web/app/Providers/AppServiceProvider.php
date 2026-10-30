<?php

declare(strict_types=1);

namespace App\Providers;

use App\Authorization\Abilities;
use App\Billing\BillingGateway;
use App\Billing\BillingGateways;
use App\Billing\Fake\FakeStripeEvents;
use App\Billing\Markets\MarketResolver;
use App\Internal\InternalClient;
use App\Internal\InternalKeyring;
use App\Internal\InternalSignature;
use App\Legal\LegalRepository;
use App\Listeners\AuditAuthenticationEvents;
use App\Notifications\AuthMailMessages;
use App\Outbox\Outbox;
use App\Outbox\OutboxMailTransport;
use App\Privacy\EmailSuppressions;
use App\Support\DevServerVite;
use App\Support\RequestId;
use App\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    private const array BUILD_COMMANDS = ['package:discover', 'vendor:publish', 'wayfinder:generate'];

    public function register(): void
    {
        $this->app->singleton(Vite::class, DevServerVite::class);
        $this->app->scoped(CurrentWorkspace::class);
        $this->app->scoped(RequestId::class);
        $this->app->singleton(Outbox::class);
        $this->app->singleton(LegalRepository::class);
        $this->app->singleton(LegalRepository::class);

        $this->app->singleton(InternalKeyring::class, fn (Application $app): InternalKeyring => new InternalKeyring(
            keys: self::internalKeys(),
            sharedSecret: is_string($secret = config('internal.secret')) ? $secret : null,
            devFallbackSecret: (string) config('internal.dev_fallback_secret'),
            production: $app->isProduction(),
        ));

        $this->app->singleton(InternalSignature::class, fn (Application $app): InternalSignature => new InternalSignature(
            $app->make(InternalKeyring::class)->fallbackSecret(),
            (int) config('internal.tolerance', 300),
        ));

        $this->app->singleton(StripeClient::class, fn (): StripeClient => new StripeClient(array_filter([
            'api_key' => (string) config('billing.stripe.secret') ?: 'sk_test_unconfigured',
            'stripe_version' => FakeStripeEvents::API_VERSION,
            'api_base' => is_string($base = config('billing.stripe.api_base')) && $base !== '' ? rtrim($base, '/') : null,
            'max_network_retries' => (int) config('billing.stripe.max_network_retries', 2),
        ], fn (mixed $value): bool => $value !== null)));

        $this->app->bind(BillingGateway::class, fn (Application $app): BillingGateway => $app->make(BillingGateways::class)->default());
    }

    /** @return array<string, string|null> */
    private static function internalKeys(): array
    {
        $keys = [];

        foreach ((array) config('internal.keys', []) as $peer => $key) {
            $keys[(string) $peer] = is_string($key) && $key !== '' ? $key : null;
        }

        return $keys;
    }

    public function boot(): void
    {
        $this->guardProductionConfiguration();
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureMail();
        $this->configureHttp();
        $this->configureRateLimiting();
        $this->configureObservability();

        Event::subscribe(AuditAuthenticationEvents::class);
    }

    public function guardProductionConfiguration(): void
    {
        if (! $this->app->isProduction() || self::runningBuildCommand()) {
            return;
        }

        $problems = $this->app->make(InternalKeyring::class)->productionProblems();

        if (config('billing.driver') === 'fake' && ! config('billing.allow_fake_in_production')) {
            $problems[] = 'BILLING_DRIVER=fake is not allowed in production (set BILLING_DRIVER=market, stripe or yookassa, or BILLING_ALLOW_FAKE_IN_PRODUCTION=true for a demo deploy)';
        }

        if (config('billing.driver') === 'yookassa' && ! $this->app->make(MarketResolver::class)->gatewayAvailable('yookassa')) {
            $problems[] = 'BILLING_DRIVER=yookassa requires YOOKASSA_SHOP_ID and YOOKASSA_SECRET_KEY';
        }

        if ($problems !== []) {
            throw new \RuntimeException("Insecure production configuration:\n  - ".implode("\n  - ", $problems));
        }
    }

    public static function runningBuildCommand(): bool
    {
        return PHP_SAPI === 'cli' && in_array($_SERVER['argv'][1] ?? null, self::BUILD_COMMANDS, true);
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    private function configureAuthorization(): void
    {
        $this->app->make(Abilities::class)->register($this->app->make(Gate::class));
    }

    private function configureMail(): void
    {
        Mail::extend('outbox', fn (): OutboxMailTransport => new OutboxMailTransport($this->app->make(Outbox::class), $this->app->make(EmailSuppressions::class)));

        VerifyEmail::toMailUsing(fn (object $notifiable, string $url) => AuthMailMessages::verifyEmail($notifiable, $url));

        ResetPassword::toMailUsing(function (object $notifiable, string $token) {
            $email = method_exists($notifiable, 'getEmailForPasswordReset') ? $notifiable->getEmailForPasswordReset() : null;
            $url = route('password.reset', ['token' => $token, 'email' => $email]);

            return AuthMailMessages::resetPassword($notifiable, $url);
        });
    }

    private function configureHttp(): void
    {
        Http::macro('internal', fn (string $service): PendingRequest => app(InternalClient::class)->to($service));
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('internal', fn (Request $request): Limit => Limit::perMinute((int) config('internal.rate_limit', 1200))
            ->by('internal|'.$request->header(InternalSignature::SERVICE_HEADER, 'unknown').'|'.$request->ip()));

        RateLimiter::for('assistant', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('assistant|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('uploads', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('uploads|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('invitations', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('invitations|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('telegram-link', fn (Request $request): Limit => Limit::perMinute(6)
            ->by('telegram-link|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('billing', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('billing|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('locale', fn (Request $request): Limit => Limit::perMinute(30)->by('locale|'.$request->ip()));

        RateLimiter::for('privacy', fn (Request $request): Limit => Limit::perHour(10)
            ->by('privacy|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('opt-out', fn (Request $request): Limit => Limit::perMinute(10)->by('opt-out|'.$request->ip()));
    }

    private function configureObservability(): void
    {
        $threshold = (int) config('logging.slow_query_ms', 0);

        if ($threshold > 0) {
            DB::listen(function (QueryExecuted $query) use ($threshold): void {
                if ($query->time >= $threshold) {
                    Log::warning('db.slow_query', [
                        'sql' => $query->sql,
                        'time_ms' => $query->time,
                        'connection' => $query->connectionName,
                    ]);
                }
            });
        }

        Event::listen(DiagnosingHealth::class, function (): void {
            DB::select('select 1');
            Cache::store()->get('health:ping');
        });
    }
}
