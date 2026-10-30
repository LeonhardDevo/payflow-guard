<?php

declare(strict_types=1);

use App\Domain\Receivables\Invoices\InvoiceTotals;
use App\Domain\Shared\Money\MoneyFormatter;
use App\Domain\Shared\Money\MoneyParser;
use Tests\Feature\Payflow\Support\Payflow;

it('formats every supported currency exactly like Intl in both locales', function (): void {
    $vectors = Payflow::fixture('money-format-vectors.json');

    expect($vectors)->not->toBeEmpty();

    foreach ($vectors as $vector) {
        expect(MoneyFormatter::format($vector['minor'], $vector['currency'], $vector['locale']))
            ->toBe($vector['expected'], "{$vector['locale']} {$vector['currency']} {$vector['minor']}");
    }
});

it('covers every configured currency in the format vectors', function (): void {
    $currencies = array_unique(array_column(Payflow::fixture('money-format-vectors.json'), 'currency'));

    foreach (array_keys(config('payflow.currencies')) as $currency) {
        expect($currencies)->toContain($currency);
    }
});

it('computes line amounts and taxes with half-up integer math', function (): void {
    foreach (Payflow::fixture('money-vectors.json')['lines'] as $line) {
        expect(InvoiceTotals::line($line['quantity'], $line['unit_price_minor'], $line['tax_rate_bp']))
            ->toBe(['amount_minor' => $line['amount_minor'], 'tax_minor' => $line['tax_minor']]);
    }
});

it('sums invoice totals and late fees', function (): void {
    foreach (Payflow::fixture('money-vectors.json')['invoices'] as $invoice) {
        expect(InvoiceTotals::totals($invoice['lines']))->toBe([
            'subtotal_minor' => $invoice['subtotal_minor'],
            'tax_minor' => $invoice['tax_minor'],
            'total_minor' => $invoice['total_minor'],
            'late_fee_applied_minor' => $invoice['late_fee_applied_minor'],
        ]);
    }
});

it('parses decimal strings into minor units without floats', function (string $input, int $expected): void {
    expect(MoneyParser::toMinor($input))->toBe($expected);
})->with([
    ['0', 0],
    ['12400', 1240000],
    ['12400.5', 1240050],
    ['0.01', 1],
    ['-12.50', -1250],
]);

it('parses lenient amounts from CSV files', function (string $input, ?int $expected): void {
    expect(MoneyParser::lenient($input))->toBe($expected);
})->with([
    ['1,234.56', 123456],
    ['1 234,56', 123456],
    ['1234.56', 123456],
    ['$9,800.00', 980000],
    ['abc', null],
]);

it('converts percentages to basis points and back', function (): void {
    expect(MoneyParser::percentToBasisPoints('20'))->toBe(2000)
        ->and(MoneyParser::percentToBasisPoints('1.5'))->toBe(150)
        ->and(MoneyParser::percentToBasisPoints('0.1'))->toBe(10)
        ->and(MoneyParser::basisPointsToPercent(150))->toBe('1.5')
        ->and(MoneyParser::basisPointsToPercent(1000))->toBe('10')
        ->and(MoneyParser::basisPointsToPercent(null))->toBeNull();
});

it('rejects malformed money input', function (): void {
    expect(fn () => MoneyParser::toMinor('12.345'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => InvoiceTotals::quantityMilli('-1'))->toThrow(InvalidArgumentException::class);
});
