<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\Payments\PaymentLinkStatusChanged;
use App\Billing\PlanLimitExceeded;
use App\Billing\PlanLimitResolvers;
use App\Billing\PlanLimits;
use App\Domain\Collections\Listeners\ApplyDeliveryReport;
use App\Domain\Collections\Models\MessageTemplate;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Collections\Models\ReminderPolicy;
use App\Domain\Guard\Models\GuardAlert;
use App\Domain\Guard\Models\PayoutRequest;
use App\Domain\Guard\Models\Vendor;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Guard\Roles\PayflowRoleGrantGuard;
use App\Domain\Imports\Models\Import;
use App\Domain\Payments\Listeners\ApplyProviderEvent;
use App\Domain\Payments\Models\Payment;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Time\Clock;
use App\Domain\Shared\Time\SystemClock;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Enums\WorkspaceRole;
use App\Events\DeliveryReported;
use App\Events\WorkspaceCreated;
use App\Http\Middleware\Payflow\ApplyClockOverride;
use App\Http\Middleware\Payflow\PayflowSharedProps;
use App\Http\Middleware\Payflow\RequireGuardStepUp;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Policies\Payflow\BankAccountPolicy;
use App\Policies\Payflow\ClientPolicy;
use App\Policies\Payflow\CollectionsConfigPolicy;
use App\Policies\Payflow\GuardAlertPolicy;
use App\Policies\Payflow\ImportPolicy;
use App\Policies\Payflow\InvoicePolicy;
use App\Policies\Payflow\PaymentPolicy;
use App\Policies\Payflow\PayoutPolicy;
use App\Policies\Payflow\ReminderRecordPolicy;
use App\Policies\Payflow\VendorPolicy;
use App\Support\Flash;
use App\Tenancy\CurrentWorkspace;
use App\Workspaces\Contracts\RoleGrantGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class PayflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(PlanLimitResolvers::class);
        $this->app->bind(RoleGrantGuard::class, PayflowRoleGrantGuard::class);

        $currencies = array_keys((array) config('payflow.currencies', []));

        if ($currencies !== []) {
            config(['product.workspace.currencies' => $currencies]);
        }
    }

    public function boot(): void
    {
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(Reminder::class, ReminderRecordPolicy::class);
        Gate::policy(Import::class, ImportPolicy::class);
        Gate::policy(ReminderPolicy::class, CollectionsConfigPolicy::class);
        Gate::policy(MessageTemplate::class, CollectionsConfigPolicy::class);
        Gate::policy(Vendor::class, VendorPolicy::class);
        Gate::policy(VendorBankAccount::class, BankAccountPolicy::class);
        Gate::policy(PayoutRequest::class, PayoutPolicy::class);
        Gate::policy(GuardAlert::class, GuardAlertPolicy::class);

        Event::listen(WorkspaceCreated::class, function (WorkspaceCreated $event): void {
            WorkspaceProvisioner::provision($event->workspace);
            DB::table('workspace_user')
                ->where('workspace_id', $event->workspace->id)
                ->where('user_id', $event->owner->id)
                ->whereNull('guard_eligible_from')
                ->update(['guard_eligible_from' => $event->workspace->created_at]);
        });
        Event::listen(PaymentLinkStatusChanged::class, ApplyProviderEvent::class);
        Event::listen(DeliveryReported::class, ApplyDeliveryReport::class);

        $this->registerPlanLimits();
        $this->registerRateLimits();

        $router = $this->app->make(Router::class);

        if (! array_key_exists('guard.stepup', $router->getMiddleware())) {
            $router->aliasMiddleware('guard.stepup', RequireGuardStepUp::class);
        }

        $router->aliasMiddleware('payflow.clock', ApplyClockOverride::class);

        Inertia::share('pf', fn (): ?array => $this->app->make(PayflowSharedProps::class)->build($this->app->make(Request::class)));

        $renderable = [$this->app->make(ExceptionHandler::class), 'renderable'];
        $renderable(fn (PlanLimitExceeded $exception, Request $request): Response => self::renderPlanLimit($exception, $request));
    }

    public static function renderPlanLimit(PlanLimitExceeded $exception, Request $request): Response
    {
        if ($request->expectsJson() && $request->header('X-Inertia') === null) {
            return response()->json(['message' => $exception->userMessage(), 'limit' => $exception->limitKey, 'upgrade' => route('settings.billing')], 402);
        }

        $workspace = app(CurrentWorkspace::class)->get();
        $plan = $workspace?->planKey() ?? 'free';
        Flash::errorMessage($exception->userMessage());
        $request->session()->flash('pf_upgrade', [
            'limit_key' => $exception->limitKey,
            'plan_suggestion' => match ($plan) {
                'free' => 'starter',
                'starter' => 'pro',
                default => 'business',
            },
        ]);

        return redirect()->back(303);
    }

    private function registerPlanLimits(): void
    {
        $limits = $this->app->make(PlanLimits::class);
        $monthStart = function (Workspace $workspace): string {
            $today = WorkspaceClock::todayIn($workspace->timezone, $this->app->make(Clock::class)->now());

            return $today->startOfMonth()->utc()->toDateTimeString();
        };
        $externalRole = WorkspaceRole::ExternalApprover->value;

        $limits->registerResolver('seats', fn (Workspace $workspace): int => $workspace->members()->wherePivot('role', '!=', $externalRole)->count()
            + WorkspaceInvitation::query()->where('workspace_id', $workspace->id)->pending()->where('role', '!=', $externalRole)->count());
        $limits->registerResolver('external_approvers', fn (Workspace $workspace): int => $workspace->members()->wherePivot('role', $externalRole)->count()
            + WorkspaceInvitation::query()->where('workspace_id', $workspace->id)->pending()->where('role', $externalRole)->count());
        $limits->registerResolver('clients', fn (Workspace $workspace): int => Client::query()->forWorkspace($workspace)->count());
        $limits->registerResolver('invoices_per_month', fn (Workspace $workspace): int => Invoice::query()->forWorkspace($workspace)
            ->where('created_at', '>=', $monthStart($workspace))->count());
        $limits->registerResolver('vendors', fn (Workspace $workspace): int => Vendor::query()->forWorkspace($workspace)->count());
        $limits->registerResolver('payouts_per_month', fn (Workspace $workspace): int => PayoutRequest::query()->forWorkspace($workspace)
            ->whereNotNull('submitted_at')->where('submitted_at', '>=', $monthStart($workspace))->count());
        $limits->registerResolver('reminder_policies', fn (Workspace $workspace): int => ReminderPolicy::query()->forWorkspace($workspace)->count());
        $limits->registerResolver('imports_per_month', fn (Workspace $workspace): int => Import::query()->forWorkspace($workspace)
            ->where('created_at', '>=', $monthStart($workspace))->count());
    }

    private function registerRateLimits(): void
    {
        RateLimiter::for('payflow-trusted', function (Request $request): array {
            $token = $request->route('token');

            return [
                Limit::perMinute(10)->by('pf-trusted-token|'.(is_string($token) ? hash('sha256', $token) : '')),
                Limit::perMinute(30)->by('pf-trusted-ip|'.$request->ip()),
            ];
        });
        RateLimiter::for('payflow-public', fn (Request $request): Limit => Limit::perMinute(60)->by('pf-public|'.$request->ip()));
        RateLimiter::for('payflow-choices', function (Request $request): array {
            $token = $request->route('token');

            return [
                Limit::perMinute(10)->by('pf-choices-token|'.(is_string($token) ? $token : '')),
                Limit::perMinute(60)->by('pf-choices-ip|'.$request->ip()),
            ];
        });
    }
}
