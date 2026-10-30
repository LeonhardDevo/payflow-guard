<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Analytics\Enums\AgingBucket;
use App\Domain\Analytics\Enums\RiskBand;
use App\Domain\Analytics\Risk\ClientStatsCalculator;
use App\Domain\Analytics\Risk\RiskRecomputer;
use App\Domain\Collections\Enums\ReminderStatus;
use App\Domain\Collections\Enums\Tone;
use App\Domain\Collections\Models\Reminder;
use App\Domain\Payments\Models\Payment;
use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Receivables\Enums\LatePayerClass;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Shared\Money\MoneyData;
use App\Domain\Shared\Time\BusinessDate;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class ReceivablesAnalytics
{
    private string $timezone;

    private string $today;

    public function __construct(private readonly Workspace $workspace, private readonly CarbonImmutable $now)
    {
        $this->timezone = WorkspaceClock::zone($workspace->timezone);
        $this->today = WorkspaceClock::todayIn($this->timezone, $now)->toDateString();
    }

    public function today(): string
    {
        return $this->today;
    }

    /** @return list<string> */
    public function currencies(): array
    {
        $recent = Invoice::query()->forWorkspace($this->workspace)
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Void->value])
            ->where(fn ($query) => $query->whereIn('status', Invoice::OUTSTANDING_STATUSES)->orWhere('issue_date', '>=', BusinessDate::addDays($this->today, -365)))
            ->distinct()
            ->pluck('currency')
            ->map(fn (mixed $currency): string => (string) $currency)
            ->all();

        $currencies = array_values(array_unique([strtoupper($this->workspace->currency), ...$recent]));
        sort($currencies);

        return $currencies;
    }

    public function resolveCurrency(?string $requested): string
    {
        $currencies = $this->currencies();
        $requested = $requested !== null ? strtoupper($requested) : null;

        return $requested !== null && in_array($requested, $currencies, true) ? $requested : strtoupper($this->workspace->currency);
    }

    /** @return Collection<int, Invoice> */
    public function outstanding(string $currency): Collection
    {
        return Invoice::query()->forWorkspace($this->workspace)
            ->with('client')
            ->whereIn('status', Invoice::OUTSTANDING_STATUSES)
            ->where('currency', $currency)
            ->get()
            ->toBase();
    }

    /** @return array<string, mixed> */
    public function kpis(string $currency, bool $riskScoring): array
    {
        $outstanding = $this->outstanding($currency);
        $outstandingAmount = (int) $outstanding->sum(fn (Invoice $invoice): int => max(0, $invoice->balanceDue()));
        $overdue = $outstanding->filter(fn (Invoice $invoice): bool => $invoice->isOpen() && $invoice->dueDate() < $this->today);
        $overdueAmount = (int) $overdue->sum(fn (Invoice $invoice): int => max(0, $invoice->balanceDue()));

        $monthStart = substr($this->today, 0, 8).'01';
        $elapsedDays = BusinessDate::diff($monthStart, $this->today);
        $previousStart = CarbonImmutable::parse($monthStart)->subMonthNoOverflow()->toDateString();
        $previousEnd = BusinessDate::addDays($previousStart, $elapsedDays);
        $collected = $this->collected($currency, $monthStart, $this->today);
        $previous = $this->collected($currency, $previousStart, $previousEnd);
        $dso = $this->dso($currency, $this->today);
        $dsoBefore = $this->dso($currency, BusinessDate::addDays($this->today, -30));
        $atRisk = $outstanding->filter(fn (Invoice $invoice): bool => $invoice->risk_band === RiskBand::High);

        return [
            'outstanding' => ['amount' => MoneyData::of($outstandingAmount, $currency), 'count' => $outstanding->count()],
            'overdue' => [
                'amount' => MoneyData::of($overdueAmount, $currency),
                'count' => $overdue->count(),
                'share' => $outstandingAmount > 0 ? round($overdueAmount / $outstandingAmount, 4) : 0,
            ],
            'collected_this_month' => [
                'amount' => MoneyData::of($collected, $currency),
                'delta_pct' => $previous > 0 ? round(($collected - $previous) / $previous * 100, 1) : null,
            ],
            'dso' => [
                'days' => $dso,
                'delta_days' => $dso !== null && $dsoBefore !== null ? round($dso - $dsoBefore, 1) : null,
                'trend' => $this->dsoTrend($currency, 6),
            ],
            'at_risk' => $riskScoring ? [
                'amount' => MoneyData::of((int) $atRisk->sum(fn (Invoice $invoice): int => max(0, $invoice->balanceDue())), $currency),
                'count' => $atRisk->count(),
            ] : null,
        ];
    }

    public function collected(string $currency, string $fromDate, string $toDate): int
    {
        $from = BusinessDate::toUtc($fromDate, '00:00', $this->timezone);
        $to = BusinessDate::toUtc(BusinessDate::addDays($toDate, 1), '00:00', $this->timezone);

        return (int) Payment::query()->forWorkspace($this->workspace)
            ->where('currency', $currency)
            ->whereIn('status', Payment::COUNTED_STATUSES)
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $to)
            ->get(['amount_minor', 'refunded_minor'])
            ->sum(fn (Payment $payment): int => $payment->amount_minor - $payment->refunded_minor);
    }

    /** @return list<array{bucket: string, amount: array{minor: int, currency: string}, count: int}> */
    public function aging(string $currency): array
    {
        $rows = [];

        foreach (AgingBucket::cases() as $bucket) {
            $rows[$bucket->value] = ['bucket' => $bucket->value, 'minor' => 0, 'count' => 0];
        }

        foreach ($this->outstanding($currency) as $invoice) {
            $bucket = self::bucket(max(0, BusinessDate::diff($invoice->dueDate(), $this->today)))->value;
            $rows[$bucket]['minor'] += max(0, $invoice->balanceDue());
            $rows[$bucket]['count']++;
        }

        return array_values(array_map(fn (array $row): array => [
            'bucket' => $row['bucket'],
            'amount' => MoneyData::of($row['minor'], $currency),
            'count' => $row['count'],
        ], $rows));
    }

    public static function bucket(int $daysOverdue): AgingBucket
    {
        return match (true) {
            $daysOverdue <= 0 => AgingBucket::Current,
            $daysOverdue <= 30 => AgingBucket::Days1To30,
            $daysOverdue <= 60 => AgingBucket::Days31To60,
            $daysOverdue <= 90 => AgingBucket::Days61To90,
            default => AgingBucket::Days90Plus,
        };
    }

    public function dso(string $currency, string $date): ?float
    {
        $invoices = Invoice::query()->forWorkspace($this->workspace)
            ->where('currency', $currency)
            ->whereNotIn('status', [InvoiceStatus::Draft->value])
            ->whereNotNull('sent_at')
            ->where('issue_date', '<=', $date)
            ->get();

        $end = BusinessDate::toUtc(BusinessDate::addDays($date, 1), '00:00', $this->timezone);
        $payments = Payment::query()->forWorkspace($this->workspace)
            ->whereIn('invoice_id', $invoices->modelKeys())
            ->whereIn('status', Payment::COUNTED_STATUSES)
            ->where('paid_at', '<', $end)
            ->get(['invoice_id', 'amount_minor', 'refunded_minor'])
            ->groupBy('invoice_id')
            ->map(fn ($group): int => (int) $group->sum(fn (Payment $payment): int => $payment->amount_minor - $payment->refunded_minor));

        $ar = 0;
        $sales = 0;
        $salesFrom = BusinessDate::addDays($date, -89);

        foreach ($invoices as $invoice) {
            $voidedBy = $invoice->voided_at !== null && $invoice->voided_at->lessThan($end);
            $writtenOffBy = $invoice->written_off_at !== null && $invoice->written_off_at->lessThan($end);

            if ($voidedBy) {
                continue;
            }

            if ($invoice->issueDate() >= $salesFrom) {
                $sales += $invoice->total_minor;
            }

            if (! $writtenOffBy) {
                $ar += max(0, $invoice->total_minor - (int) ($payments[$invoice->id] ?? 0));
            }
        }

        return self::dsoValue($ar, $sales);
    }

    public static function dsoValue(int $arMinor, int $sales90Minor): ?float
    {
        return $sales90Minor > 0 ? round($arMinor / $sales90Minor * 90, 1) : null;
    }

    /** @return list<array{date: string, days: float|null}> */
    public function dsoTrend(string $currency, int $points): array
    {
        $trend = [];
        $monthStart = CarbonImmutable::parse(substr($this->today, 0, 8).'01');

        for ($i = $points - 1; $i >= 1; $i--) {
            $date = $monthStart->subMonthsNoOverflow($i - 1)->subDay()->toDateString();
            $trend[] = ['date' => $date, 'days' => $this->dso($currency, $date)];
        }

        $trend[] = ['date' => $this->today, 'days' => $this->dso($currency, $this->today)];

        return $trend;
    }

    /** @return list<array{month: string, collected: array{minor: int, currency: string}, invoiced: array{minor: int, currency: string}, on_time_rate: float|null}> */
    public function monthly(string $currency, int $months): array
    {
        $monthStart = CarbonImmutable::parse(substr($this->today, 0, 8).'01');
        $first = $monthStart->subMonthsNoOverflow($months - 1);
        $from = BusinessDate::toUtc($first->toDateString(), '00:00', $this->timezone);

        $collected = Payment::query()->forWorkspace($this->workspace)
            ->where('currency', $currency)
            ->whereIn('status', Payment::COUNTED_STATUSES)
            ->where('paid_at', '>=', $from)
            ->get(['paid_at', 'amount_minor', 'refunded_minor'])
            ->groupBy(fn (Payment $payment): string => substr(WorkspaceClock::localDate($payment->paid_at, $this->timezone), 0, 7))
            ->map(fn ($group): int => (int) $group->sum(fn (Payment $payment): int => $payment->amount_minor - $payment->refunded_minor));

        $invoiced = Invoice::query()->forWorkspace($this->workspace)
            ->where('currency', $currency)
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Void->value])
            ->where('issue_date', '>=', $first->toDateString())
            ->get(['issue_date', 'total_minor'])
            ->groupBy(fn (Invoice $invoice): string => substr($invoice->issueDate(), 0, 7))
            ->map(fn ($group): int => (int) $group->sum('total_minor'));

        $closed = Invoice::query()->forWorkspace($this->workspace)
            ->where('currency', $currency)
            ->whereIn('status', [InvoiceStatus::Paid->value, InvoiceStatus::Uncollectible->value])
            ->where(fn ($query) => $query->where('paid_at', '>=', $from)->orWhere('written_off_at', '>=', $from))
            ->get()
            ->filter(fn (Invoice $invoice): bool => RiskRecomputer::isClosed($invoice))
            ->groupBy(function (Invoice $invoice): string {
                $closedAt = $invoice->status === InvoiceStatus::Uncollectible ? $invoice->written_off_at : $invoice->paid_at;

                return $closedAt !== null ? substr(WorkspaceClock::localDate($closedAt, $this->timezone), 0, 7) : '';
            });

        $points = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $first->addMonthsNoOverflow($i)->format('Y-m');
            $group = $closed->get($month);
            $onTime = null;

            if ($group !== null && $group->count() > 0) {
                $onTime = round($group->filter(fn (Invoice $invoice): bool => ! ClientStatsCalculator::isLate(RiskRecomputer::daysLate($invoice, $this->timezone)))->count() / $group->count(), 4);
            }

            $points[] = [
                'month' => $month,
                'collected' => MoneyData::of((int) ($collected[$month] ?? 0), $currency),
                'invoiced' => MoneyData::of((int) ($invoiced[$month] ?? 0), $currency),
                'on_time_rate' => $onTime,
            ];
        }

        return $points;
    }

    /** @return array{weeks: list<array{week_start: string, expected: array{minor: int, currency: string}, at_risk: array{minor: int, currency: string}, invoices: int}>, later_total: array{minor: int, currency: string}} */
    public function forecast(string $currency): array
    {
        $weekStart = CarbonImmutable::parse($this->today)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
        $weeks = [];

        for ($i = 0; $i < 8; $i++) {
            $weeks[BusinessDate::addDays($weekStart, $i * 7)] = ['expected' => 0, 'at_risk' => 0, 'invoices' => 0];
        }

        $later = 0;
        $horizon = BusinessDate::addDays($weekStart, 56);

        foreach ($this->outstanding($currency) as $invoice) {
            if (! $invoice->isOpen() || $invoice->balanceDue() <= 0) {
                continue;
            }

            $expected = $invoice->expected_paid_on?->format('Y-m-d') ?? BusinessDate::max($invoice->dueDate(), $this->today);

            if ($expected >= $horizon) {
                $later += $invoice->balanceDue();

                continue;
            }

            $index = intdiv(max(0, BusinessDate::diff($weekStart, $expected)), 7);
            $key = BusinessDate::addDays($weekStart, $index * 7);

            if (! isset($weeks[$key])) {
                continue;
            }

            $week = $weeks[$key];

            if ($invoice->risk_band === RiskBand::High) {
                $week['at_risk'] += $invoice->balanceDue();
            } else {
                $week['expected'] += $invoice->balanceDue();
            }

            $week['invoices']++;
            $weeks[$key] = $week;
        }

        $result = [];

        foreach ($weeks as $start => $week) {
            $result[] = [
                'week_start' => $start,
                'expected' => MoneyData::of($week['expected'], $currency),
                'at_risk' => MoneyData::of($week['at_risk'], $currency),
                'invoices' => $week['invoices'],
            ];
        }

        return ['weeks' => $result, 'later_total' => MoneyData::of($later, $currency)];
    }

    /** @return list<array<string, mixed>> */
    public function latePayers(string $currency, int $limit): array
    {
        $clients = Client::query()->forWorkspace($this->workspace)
            ->whereIn('late_payer_class', [LatePayerClass::Chronic->value, LatePayerClass::Occasional->value])
            ->orderByDesc('avg_days_late')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        $lastPayments = Payment::query()->forWorkspace($this->workspace)
            ->whereIn('client_id', $clients->modelKeys())
            ->whereIn('status', Payment::COUNTED_STATUSES)
            ->selectRaw('client_id, max(paid_at) as last_paid_at')
            ->groupBy('client_id')
            ->pluck('last_paid_at', 'client_id');

        return array_values($clients->map(fn (Client $client): array => [
            'client' => ['id' => $client->id, 'name' => $client->name, 'late_payer_class' => $client->late_payer_class->value],
            'avg_days_late' => $client->avgDaysLate(),
            'on_time_rate' => $client->onTimeRate(),
            'open_balance' => MoneyData::of($client->open_balance_minor, $client->currency),
            'overdue_balance' => MoneyData::of($client->overdue_balance_minor, $client->currency),
            'last_payment_at' => isset($lastPayments[$client->id]) ? CarbonImmutable::parse((string) $lastPayments[$client->id])->utc()->format('Y-m-d\TH:i:s\Z') : null,
        ])->all());
    }

    /** @return list<array{band: string, amount: array{minor: int, currency: string}, count: int}> */
    public function riskMix(string $currency): array
    {
        $outstanding = $this->outstanding($currency);
        $rows = [];

        foreach (RiskBand::cases() as $band) {
            $group = $outstanding->filter(fn (Invoice $invoice): bool => $invoice->risk_band === $band);
            $rows[] = [
                'band' => $band->value,
                'amount' => MoneyData::of((int) $group->sum(fn (Invoice $invoice): int => max(0, $invoice->balanceDue())), $currency),
                'count' => $group->count(),
            ];
        }

        return $rows;
    }

    /** @return list<array{tone: string, sent: int, paid_within_7d: int, rate: float|null}> */
    public function toneEffectiveness(): array
    {
        $since = $this->now->subDays(90);
        $reminders = Reminder::query()->forWorkspace($this->workspace)
            ->where('status', ReminderStatus::Sent->value)
            ->where('sent_at', '>=', $since)
            ->get(['id', 'invoice_id', 'tone', 'sent_at']);

        $payments = Payment::query()->forWorkspace($this->workspace)
            ->whereIn('invoice_id', $reminders->pluck('invoice_id')->unique()->values()->all())
            ->whereIn('status', Payment::COUNTED_STATUSES)
            ->get(['invoice_id', 'paid_at'])
            ->groupBy('invoice_id');

        $rows = [];

        foreach (Tone::cases() as $tone) {
            $sent = $reminders->filter(fn (Reminder $reminder): bool => $reminder->tone === $tone);
            $paid = $sent->filter(function (Reminder $reminder) use ($payments): bool {
                $sentAt = $reminder->sent_at;

                return $sentAt !== null && ($payments->get($reminder->invoice_id) ?? collect())
                    ->contains(fn (Payment $payment): bool => $payment->paid_at->greaterThanOrEqualTo($sentAt) && $payment->paid_at->lessThanOrEqualTo($sentAt->copy()->addDays(7)));
            });

            $rows[] = [
                'tone' => $tone->value,
                'sent' => $sent->count(),
                'paid_within_7d' => $paid->count(),
                'rate' => $sent->count() > 0 ? round($paid->count() / $sent->count(), 4) : null,
            ];
        }

        return $rows;
    }
}
