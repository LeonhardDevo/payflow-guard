<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Billing\PlanLimits;
use App\Domain\Analytics\Enums\RiskBand;
use App\Domain\Collections\Enums\ReminderStatus;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Guard\Enums\BankAccountStatus;
use App\Domain\Guard\Models\Vendor;
use App\Domain\Guard\Models\VendorBankAccount;
use App\Domain\Payments\Links\PaymentAccountState;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Money\MoneyFormatter;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Time\BusinessDate;
use App\Enums\WorkspaceRole;
use App\Http\Resources\Payflow\InvoiceResource;
use App\Http\Resources\Payflow\ReminderResource;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

final readonly class DashboardBuilder
{
    public function __construct(
        private PlanLimits $limits,
        private PaymentAccountState $accounts,
    ) {}

    /** @return array<string, mixed> */
    public function build(Workspace $workspace, User $viewer, WorkspaceRole $role, ?string $currency, CarbonImmutable $now): array
    {
        $analytics = new ReceivablesAnalytics($workspace, $now);
        $currency = $analytics->resolveCurrency($currency);
        $risk = $this->limits->hasFeature($workspace, 'risk_scoring');
        $advanced = $this->limits->hasFeature($workspace, 'analytics_advanced');
        $today = $analytics->today();

        $attention = Invoice::query()->forWorkspace($workspace)
            ->with('client')
            ->whereIn('status', Invoice::OPEN_STATUSES)
            ->where('currency', $currency)
            ->where('due_date', '<', $today)
            ->orderBy('due_date')
            ->orderByRaw('(total_minor - amount_paid_minor) desc')
            ->limit(5)
            ->get();

        $upcoming = Reminder::query()->forWorkspace($workspace)
            ->with(['invoice', 'client'])
            ->where('status', ReminderStatus::Scheduled->value)
            ->where('scheduled_for', '<=', $now->addDays(7))
            ->orderBy('scheduled_for')
            ->limit(8)
            ->get();

        $guardVisible = $role->allows('vendors.view') && $role->allows('payouts.view');

        return [
            'currency' => $currency,
            'currencies' => $analytics->currencies(),
            'kpis' => $analytics->kpis($currency, $risk),
            'aging' => $analytics->aging($currency),
            'forecast' => $advanced ? $analytics->forecast($currency) : null,
            'attention' => InvoiceResource::listItems($attention, $today, $risk),
            'upcoming_reminders' => $upcoming->map(fn (Reminder $reminder): array => ReminderResource::upcoming($reminder))->values()->all(),
            'late_payers' => $analytics->latePayers($currency, 5),
            'guard' => $guardVisible ? GuardSummary::forDashboard($workspace, $viewer, $role) : null,
            'insights' => $this->insights($workspace, $role, $analytics, $currency, $viewer->locale),
            'onboarding' => $this->onboarding($workspace, $viewer),
        ];
    }

    /** @return list<array{code: string, severity: string, params: array<string, string|int|float>, action: array{label_key: string, href: string}|null}> */
    public function insights(Workspace $workspace, WorkspaceRole $role, ReceivablesAnalytics $analytics, string $currency, string $locale): array
    {
        $insights = [];
        $today = $analytics->today();

        if ($role->allows('vendors.view')) {
            $pending = VendorBankAccount::query()->forWorkspace($workspace)->where('status', BankAccountStatus::PendingVerification->value);
            $count = (clone $pending)->count();

            if ($count > 0) {
                $high = (clone $pending)->where('risk_band', RiskBand::High->value)->count();
                $insights[] = [
                    'code' => 'bank_change_pending',
                    'severity' => $high > 0 ? 'critical' : 'warning',
                    'params' => ['count' => $count, 'high' => $high],
                    'action' => ['label_key' => 'pf.insights.actions.review_changes', 'href' => url('/guard')],
                ];
            }
        }

        $chronic = Client::query()->forWorkspace($workspace)
            ->where('late_payer_class', 'chronic')
            ->where('currency', $currency)
            ->where('overdue_balance_minor', '>', 0)
            ->orderByDesc('overdue_balance_minor')
            ->first();

        if ($chronic !== null) {
            $insights[] = [
                'code' => 'chronic_client_overdue',
                'severity' => 'warning',
                'params' => [
                    'client' => $chronic->name,
                    'avg_days_late' => round($chronic->avgDaysLate(), 1),
                    'overdue' => MoneyFormatter::format($chronic->overdue_balance_minor, $chronic->currency, $locale),
                ],
                'action' => ['label_key' => 'pf.insights.actions.open_client', 'href' => route('clients.show', $chronic->id)],
            ];
        }

        $typical = (int) round($analytics->collected($currency, BusinessDate::addDays($today, -89), $today) / 90 * 14);

        if ($typical > 0) {
            $until = BusinessDate::addDays($today, 13);
            $expected = (int) Invoice::query()->forWorkspace($workspace)
                ->whereIn('status', Invoice::OPEN_STATUSES)
                ->where('currency', $currency)
                ->whereNotNull('expected_paid_on')
                ->where('expected_paid_on', '<=', $until)
                ->get()
                ->sum(fn (Invoice $invoice): int => max(0, $invoice->balanceDue()));

            if ($expected < $typical / 2) {
                $insights[] = [
                    'code' => 'forecast_gap',
                    'severity' => 'warning',
                    'params' => [
                        'expected' => MoneyFormatter::format($expected, $currency, $locale),
                        'typical' => MoneyFormatter::format($typical, $currency, $locale),
                    ],
                    'action' => ['label_key' => 'pf.insights.actions.open_analytics', 'href' => route('analytics.index')],
                ];
            }
        }

        if (WorkspaceProvisioner::settingsFor($workspace)->track_invoice_views) {
            $unviewed = Invoice::query()->forWorkspace($workspace)
                ->whereIn('status', Invoice::OPEN_STATUSES)
                ->whereNull('first_viewed_at')
                ->whereNotNull('sent_at')
                ->where('sent_at', '<=', CarbonImmutable::parse($today)->subDays(5))
                ->count();

            if ($unviewed > 0) {
                $insights[] = [
                    'code' => 'unviewed_invoices',
                    'severity' => 'info',
                    'params' => ['count' => $unviewed],
                    'action' => ['label_key' => 'pf.insights.actions.open_invoices', 'href' => route('invoices.index', ['status' => 'outstanding'])],
                ];
            }
        }

        $failed = Reminder::query()->forWorkspace($workspace)
            ->where('status', ReminderStatus::Failed->value)
            ->where('failed_at', '>=', CarbonImmutable::parse($today)->subDays(7))
            ->count();

        if ($failed > 0) {
            $insights[] = [
                'code' => 'reminders_failed',
                'severity' => 'warning',
                'params' => ['count' => $failed],
                'action' => ['label_key' => 'pf.insights.actions.open_reminders', 'href' => route('reminders.index', ['tab' => 'failed'])],
            ];
        }

        return array_slice($insights, 0, 3);
    }

    /** @return array<string, mixed> */
    public function onboarding(Workspace $workspace, User $viewer): array
    {
        return [
            'has_clients' => Client::query()->forWorkspace($workspace)->exists(),
            'has_invoices' => Invoice::query()->forWorkspace($workspace)->exists(),
            'provider_connected' => $this->accounts->live($workspace) !== null,
            'telegram_linked' => $viewer->telegramAccount !== null,
            'has_vendors' => Vendor::query()->forWorkspace($workspace)->exists(),
            'documents_indexed' => Document::query()->forWorkspace($workspace)->where('status', 'indexed')->count(),
            'dismissed' => WorkspaceProvisioner::settingsFor($workspace)->onboarding_dismissed,
        ];
    }
}
