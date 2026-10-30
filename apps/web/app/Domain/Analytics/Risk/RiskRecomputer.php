<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Risk;

use App\Domain\Receivables\Enums\EventActorType;
use App\Domain\Receivables\Enums\InvoiceEventType;
use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Receivables\Models\Client;
use App\Domain\Receivables\Models\Invoice;
use App\Domain\Receivables\Timeline\InvoiceTimeline;
use App\Domain\Shared\Settings\WorkspaceProvisioner;
use App\Domain\Shared\Time\BusinessDate;
use App\Domain\Shared\Time\WorkspaceClock;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final readonly class RiskRecomputer
{
    public function __construct(private InvoiceTimeline $timeline) {}

    /** @return array{clients: int, invoices: int, band_changes: int} */
    public function workspace(Workspace $workspace, CarbonImmutable $now, ?int $clientId = null): array
    {
        $context = $this->context($workspace, $now);
        $summary = ['clients' => 0, 'invoices' => 0, 'band_changes' => 0];

        $clients = Client::query()
            ->forWorkspace($workspace)
            ->withTrashed()
            ->when($clientId !== null, fn ($query) => $query->whereKey($clientId))
            ->get();

        foreach ($clients as $client) {
            $result = $this->applyClient($client, $context);
            $summary['clients']++;
            $summary['invoices'] += $result['invoices'];
            $summary['band_changes'] += $result['band_changes'];
        }

        return $summary;
    }

    public function client(Client $client, CarbonImmutable $now): void
    {
        $workspace = Workspace::query()->findOrFail($client->workspace_id);
        $this->workspace($workspace, $now, $client->id);
    }

    /** @return array{today: string, now: CarbonImmutable, timezone: string, p0: float, w0: float, track_views: bool, invoices: Collection<array-key, Collection<int, Invoice>>} */
    private function context(Workspace $workspace, CarbonImmutable $now): array
    {
        $timezone = WorkspaceClock::zone($workspace->timezone);
        $today = WorkspaceClock::todayIn($timezone, $now)->toDateString();
        $windowStart = BusinessDate::addDays($today, -731);

        $invoices = Invoice::query()
            ->forWorkspace($workspace)
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Void->value])
            ->where(fn ($query) => $query->where('issue_date', '>=', $windowStart)->orWhereIn('status', Invoice::OUTSTANDING_STATUSES))
            ->orderBy('issue_date')
            ->orderBy('id')
            ->get();

        $yearStart = BusinessDate::addDays($today, -365);
        $recentClosed = $invoices->filter(fn (Invoice $invoice): bool => $invoice->issueDate() >= $yearStart && self::isClosed($invoice));
        $recentDays = $recentClosed->map(fn (Invoice $invoice): int => self::daysLate($invoice, $timezone))->values();
        $count = $recentDays->count();

        return [
            'today' => $today,
            'now' => $now,
            'timezone' => $timezone,
            'p0' => $count >= 10 ? (float) ($recentDays->filter(fn (int $days): bool => ClientStatsCalculator::isLate($days))->count() / $count) : InvoiceRiskScorer::DEFAULT_LATE_SHARE,
            'w0' => $count >= 10 ? (float) $recentDays->avg() : 7.0,
            'track_views' => WorkspaceProvisioner::settingsFor($workspace)->track_invoice_views,
            'invoices' => $invoices->toBase()->groupBy('client_id'),
        ];
    }

    /**
     * @param  array{today: string, now: CarbonImmutable, timezone: string, p0: float, w0: float, track_views: bool, invoices: Collection<array-key, Collection<int, Invoice>>}  $context
     * @return array{invoices: int, band_changes: int}
     */
    private function applyClient(Client $client, array $context): array
    {
        $invoices = $context['invoices']->get($client->id) ?? collect();
        $windowStart = BusinessDate::addDays($context['today'], -731);
        $closed = $invoices->filter(fn (Invoice $invoice): bool => self::isClosed($invoice) && $invoice->issueDate() >= $windowStart)->values();
        $daysLate = array_values($closed->map(fn (Invoice $invoice): int => self::daysLate($invoice, $context['timezone']))->all());
        $stats = ClientStatsCalculator::compute($daysLate);
        $today = $context['today'];

        $outstanding = $invoices->filter(fn (Invoice $invoice): bool => $invoice->isOutstanding())->values();
        $overdue = $outstanding->filter(fn (Invoice $invoice): bool => $invoice->isOpen() && BusinessDate::diff($invoice->dueDate(), $today) >= 1);
        $yearAgo = $context['now']->subDays(365);
        $disputeHistory = $invoices->contains(fn (Invoice $invoice): bool => $invoice->disputed_at !== null && $invoice->disputed_at->greaterThanOrEqualTo($yearAgo));

        $client->forceFill([
            'paid_invoices_count' => $stats['paid_invoices_count'],
            'late_invoices_count' => $stats['late_invoices_count'],
            'avg_days_late' => $stats['avg_days_late'],
            'on_time_rate' => $stats['on_time_rate'],
            'late_payer_class' => $stats['class'],
            'open_balance_minor' => (int) $outstanding->filter(fn (Invoice $invoice): bool => $invoice->currency === $client->currency)->sum(fn (Invoice $invoice): int => max(0, $invoice->balanceDue())),
            'overdue_balance_minor' => (int) $overdue->filter(fn (Invoice $invoice): bool => $invoice->currency === $client->currency)->sum(fn (Invoice $invoice): int => max(0, $invoice->balanceDue())),
            'stats_computed_at' => $context['now'],
        ])->saveQuietly();

        $bandChanges = 0;
        $updated = 0;

        foreach ($invoices as $invoice) {
            if (! $invoice->isOutstanding()) {
                if ($invoice->risk_score !== null || $invoice->risk_band !== null) {
                    $invoice->forceFill(['risk_score' => null, 'risk_band' => null, 'risk_factors' => null, 'expected_paid_on' => null, 'risk_computed_at' => $context['now']])->saveQuietly();
                    $updated++;
                }

                continue;
            }

            $windowTotals = $invoices
                ->filter(fn (Invoice $other): bool => $other->currency === $invoice->currency && $other->issueDate() >= $windowStart)
                ->map(fn (Invoice $other): int => $other->total_minor)
                ->all();
            $windowTotals = array_values($windowTotals);
            $median = ClientStatsCalculator::median($windowTotals);
            $ratio = $stats['paid_invoices_count'] >= 3 && $median > 0 ? $invoice->total_minor / $median : 1.0;
            $d = BusinessDate::diff($invoice->dueDate(), $today);
            $notViewed = $context['track_views']
                && $invoice->sent_at !== null
                && $invoice->first_viewed_at === null
                && $invoice->sent_at->lessThanOrEqualTo($context['now']->subDays(3));

            $result = InvoiceRiskScorer::score(new RiskInput(
                closedCount: $stats['paid_invoices_count'],
                lateCount: $stats['late_invoices_count'],
                workspaceLateShare: $context['p0'],
                avgDaysLate: $stats['avg_days_late'],
                otherOverdueInvoices: $overdue->filter(fn (Invoice $other): bool => $other->id !== $invoice->id)->count(),
                amountRatio: $ratio,
                termsDays: BusinessDate::diff($invoice->issueDate(), $invoice->dueDate()),
                daysSinceDue: $d,
                notViewed: $notViewed,
                disputeHistory: $disputeHistory,
            ));

            $expected = BusinessDate::max($today, BusinessDate::addDays($invoice->dueDate(), ClientStatsCalculator::expectedDelayDays($daysLate, $context['w0'])));
            $previousBand = $invoice->risk_band;

            $invoice->forceFill([
                'risk_score' => $result->score,
                'risk_band' => $result->band,
                'risk_factors' => $result->factors,
                'expected_paid_on' => $expected,
                'risk_computed_at' => $context['now'],
            ])->saveQuietly();
            $updated++;

            if ($previousBand !== null && $previousBand !== $result->band) {
                $bandChanges++;
                $this->timeline->record($invoice, InvoiceEventType::RiskBandChanged, ['from' => $previousBand->value, 'to' => $result->band->value], actorType: EventActorType::System, at: $context['now']);
            }
        }

        return ['invoices' => $updated, 'band_changes' => $bandChanges];
    }

    public static function isClosed(Invoice $invoice): bool
    {
        return ($invoice->status === InvoiceStatus::Paid && $invoice->paid_at !== null && ! $invoice->paid_at_estimated)
            || ($invoice->status === InvoiceStatus::Uncollectible && $invoice->written_off_at !== null);
    }

    public static function daysLate(Invoice $invoice, string $timezone): int
    {
        $closedAt = $invoice->status === InvoiceStatus::Uncollectible ? $invoice->written_off_at : $invoice->paid_at;

        if ($closedAt === null) {
            return 0;
        }

        return max(0, BusinessDate::diff($invoice->dueDate(), WorkspaceClock::localDate($closedAt, $timezone)));
    }
}
