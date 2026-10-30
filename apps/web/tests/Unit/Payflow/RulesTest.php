<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\AgingBucket;
use App\Domain\Analytics\ReceivablesAnalytics;
use App\Domain\Analytics\Risk\ClientStatsCalculator;
use App\Domain\Analytics\Risk\InvoiceRiskScorer;
use App\Domain\Analytics\Risk\RiskInput;
use App\Domain\Collections\Enums\StepTone;
use App\Domain\Collections\Enums\Tone;
use App\Domain\Collections\Tone\ToneEngine;
use App\Domain\Collections\Tone\ToneInput;
use App\Domain\Receivables\Enums\InvoiceDisplayStatus;
use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Receivables\Enums\LateFeeType;
use App\Domain\Receivables\Enums\LatePayerClass;
use App\Domain\Receivables\Enums\ToneCeiling;
use App\Domain\Receivables\Invoices\InvoiceDisplayStatusResolver;
use App\Domain\Receivables\Invoices\InvoiceStateMachine;
use App\Domain\Receivables\LateFees\LateFeeCalculator;
use App\Domain\Receivables\LateFees\LateFeeTerms;
use App\Domain\Shared\Exceptions\InvalidInvoiceTransition;
use Tests\Feature\Payflow\Support\Payflow;

it('computes late fees for the contract vectors', function (LateFeeTerms $terms, int $balance, int $total, int $days, int $expected): void {
    expect(LateFeeCalculator::fee($terms, $balance, $total, $days))->toBe($expected);
})->with([
    'Helios monthly 1.5%, d52, grace 10, cap 15%' => [new LateFeeTerms(LateFeeType::PercentMonthly, 150, null, 10, 1500), 1240000, 1240000, 52, 26040],
    'Baltic daily 0.1%, d7' => [new LateFeeTerms(LateFeeType::PercentDaily, 10, null, 0, 1000), 385000, 385000, 7, 2695],
    'Baltic daily 0.1%, d120 capped' => [new LateFeeTerms(LateFeeType::PercentDaily, 10, null, 0, 1000), 385000, 385000, 120, 38500],
    'fixed 40.00, d5 grace 3' => [new LateFeeTerms(LateFeeType::Fixed, null, 4000, 3, null), 100000, 100000, 5, 4000],
    'fixed within grace' => [new LateFeeTerms(LateFeeType::Fixed, null, 4000, 3, null), 100000, 100000, 3, 0],
    'none' => [LateFeeTerms::none(), 100000, 100000, 30, 0],
]);

it('subtracts already applied late fees from the suggestion', function (): void {
    $terms = new LateFeeTerms(LateFeeType::PercentMonthly, 150, null, 10, 1500);

    expect(LateFeeCalculator::suggested($terms, 1240000, 1240000, 52, 20000))->toBe(6040)
        ->and(LateFeeCalculator::suggested($terms, 1240000, 1240000, 52, 30000))->toBe(0);
});

it('suggests tones for the contract vectors', function (int $d, string $class, float $ratio, int $sent, string $ceiling, string $step, string $expected): void {
    $suggestion = ToneEngine::suggest(new ToneInput(
        daysSinceDue: $d,
        clientClass: LatePayerClass::from($class),
        amountRatio: $ratio,
        remindersSent: $sent,
        otherOverdueInvoices: 0,
        ceiling: ToneCeiling::from($ceiling),
        stepTone: StepTone::from($step),
        avgDaysLate: 18.4,
        onTimeRate: 0.9,
    ));

    expect($suggestion->tone->value)->toBe($expected)
        ->and($suggestion->reasons)->not->toBeEmpty();
})->with([
    [-3, 'new', 1.0, 0, 'none', 'auto', 'friendly'],
    [4, 'reliable', 1.0, 1, 'none', 'auto', 'friendly'],
    [10, 'reliable', 1.0, 2, 'none', 'auto', 'friendly'],
    [10, 'chronic', 1.0, 2, 'none', 'auto', 'firm'],
    [20, 'chronic', 2.5, 3, 'none', 'auto', 'firm'],
    [25, 'occasional', 1.0, 3, 'none', 'auto', 'firm'],
    [25, 'chronic', 2.5, 4, 'neutral', 'auto', 'neutral'],
    [52, 'chronic', 1.6, 5, 'none', 'auto', 'final'],
    [0, 'new', 1.0, 0, 'none', 'final', 'final'],
]);

it('explains tone decisions with reason codes', function (): void {
    $capped = ToneEngine::suggest(new ToneInput(25, LatePayerClass::Chronic, 2.5, 4, 0, ToneCeiling::Neutral));
    $fixed = ToneEngine::suggest(new ToneInput(0, LatePayerClass::New, 1.0, 0, 0, ToneCeiling::None, StepTone::Final));
    $multiple = ToneEngine::suggest(new ToneInput(2, LatePayerClass::New, 1.0, 0, 2));

    expect(array_column($capped->reasons, 'code'))->toContain('client_tone_ceiling', 'client_chronic', 'large_amount')
        ->and(array_column($fixed->reasons, 'code'))->toBe(['step_fixed_tone', 'due_today'])
        ->and($multiple->tone)->toBe(Tone::Neutral)
        ->and(array_column($multiple->reasons, 'code'))->toContain('multiple_overdue')
        ->and($capped->withCap(Tone::Friendly, 'first_contact_cap')->tone)->toBe(Tone::Friendly);
});

it('scores late-payment risk for the contract vectors', function (): void {
    foreach (Payflow::fixture('risk-vectors.json') as $vector) {
        $result = InvoiceRiskScorer::score(new RiskInput(
            closedCount: $vector['n'],
            lateCount: $vector['late'],
            workspaceLateShare: $vector['p0'],
            avgDaysLate: (float) $vector['avg'],
            otherOverdueInvoices: $vector['other_overdue'],
            amountRatio: (float) $vector['ratio'],
            termsDays: $vector['terms'],
            daysSinceDue: $vector['d'],
            notViewed: $vector['not_viewed'],
            disputeHistory: $vector['dispute'],
        ));

        expect(abs($result->z - $vector['z']))->toBeLessThan(0.0005, $vector['case'])
            ->and($result->score)->toBe($vector['score'], $vector['case'])
            ->and($result->band->value)->toBe($vector['band'], $vector['case'])
            ->and(count($result->factors))->toBeLessThanOrEqual(3);
    }
});

it('classifies late payers and computes stats', function (): void {
    $helios = ClientStatsCalculator::compute([21, 14, 30, 9, 25, 18, 12, 18]);
    $orbit = ClientStatsCalculator::compute([0, 5, 2, 11, 0, 7]);
    $bright = ClientStatsCalculator::compute([0, 0, 2, 0, 1, 0, 0, 3, 0, 0]);

    expect($helios['class'])->toBe(LatePayerClass::Chronic)
        ->and($helios['avg_days_late'])->toBe(18.38)
        ->and($helios['late_invoices_count'])->toBe(8)
        ->and($orbit['class'])->toBe(LatePayerClass::Occasional)
        ->and($bright['class'])->toBe(LatePayerClass::Reliable)
        ->and($bright['on_time_rate'])->toBe(1.0)
        ->and(ClientStatsCalculator::compute([0])['class'])->toBe(LatePayerClass::New)
        ->and(ClientStatsCalculator::compute([])['on_time_rate'])->toBeNull()
        ->and(ClientStatsCalculator::expectedDelayDays([10, 20], 7.0))->toBe(11)
        ->and(ClientStatsCalculator::median([3, 1, 2, 10]))->toBe(2.5);
});

it('derives the display status in contract order', function (InvoiceStatus $status, string $due, InvoiceDisplayStatus $expected): void {
    expect(InvoiceDisplayStatusResolver::resolve($status, $due, '2026-10-22'))->toBe($expected);
})->with([
    [InvoiceStatus::Void, '2026-01-01', InvoiceDisplayStatus::Void],
    [InvoiceStatus::Paid, '2026-01-01', InvoiceDisplayStatus::Paid],
    [InvoiceStatus::Uncollectible, '2026-01-01', InvoiceDisplayStatus::Uncollectible],
    [InvoiceStatus::Disputed, '2026-01-01', InvoiceDisplayStatus::Disputed],
    [InvoiceStatus::Draft, '2026-01-01', InvoiceDisplayStatus::Draft],
    [InvoiceStatus::PartiallyPaid, '2026-10-21', InvoiceDisplayStatus::Overdue],
    [InvoiceStatus::PartiallyPaid, '2026-10-30', InvoiceDisplayStatus::PartiallyPaid],
    [InvoiceStatus::Open, '2026-10-25', InvoiceDisplayStatus::DueSoon],
    [InvoiceStatus::Open, '2026-10-22', InvoiceDisplayStatus::DueSoon],
    [InvoiceStatus::Open, '2026-10-26', InvoiceDisplayStatus::Open],
]);

it('guards invoice state transitions', function (): void {
    expect(InvoiceStateMachine::can(InvoiceStatus::Draft, InvoiceStatus::Open))->toBeTrue()
        ->and(InvoiceStateMachine::can(InvoiceStatus::Paid, InvoiceStatus::Void))->toBeFalse()
        ->and(InvoiceStateMachine::can(InvoiceStatus::Uncollectible, InvoiceStatus::Paid))->toBeTrue()
        ->and(InvoiceStateMachine::settledStatus(InvoiceStatus::Open, 10000, 4000))->toBe(InvoiceStatus::PartiallyPaid)
        ->and(InvoiceStateMachine::settledStatus(InvoiceStatus::PartiallyPaid, 10000, 10000))->toBe(InvoiceStatus::Paid)
        ->and(InvoiceStateMachine::settledStatus(InvoiceStatus::Paid, 10000, 0))->toBe(InvoiceStatus::Open)
        ->and(InvoiceStateMachine::settledStatus(InvoiceStatus::Disputed, 10000, 10000))->toBe(InvoiceStatus::Disputed)
        ->and(InvoiceStateMachine::settledStatus(InvoiceStatus::Uncollectible, 10000, 3000))->toBe(InvoiceStatus::Uncollectible)
        ->and(InvoiceStateMachine::editableFields(InvoiceStatus::Open))->toBe(['notes', 'terms', 'po_number', 'due_date'])
        ->and(InvoiceStateMachine::editableFields(InvoiceStatus::Paid))->toBe([])
        ->and(fn () => InvoiceStateMachine::assert(InvoiceStatus::Void, InvoiceStatus::Open))->toThrow(InvalidInvoiceTransition::class);
});

it('buckets aging and computes DSO', function (): void {
    expect(ReceivablesAnalytics::bucket(0))->toBe(AgingBucket::Current)
        ->and(ReceivablesAnalytics::bucket(30))->toBe(AgingBucket::Days1To30)
        ->and(ReceivablesAnalytics::bucket(31))->toBe(AgingBucket::Days31To60)
        ->and(ReceivablesAnalytics::bucket(90))->toBe(AgingBucket::Days61To90)
        ->and(ReceivablesAnalytics::bucket(91))->toBe(AgingBucket::Days90Plus)
        ->and(ReceivablesAnalytics::dsoValue(500000, 1500000))->toBe(30.0)
        ->and(ReceivablesAnalytics::dsoValue(100, 0))->toBeNull();
});
