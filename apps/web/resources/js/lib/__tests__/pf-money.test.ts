import { describe, expect, it } from 'vitest';
import formatVectors from '../../../../tests/Fixtures/payflow/money-format-vectors.json';
import vectors from '../../../../tests/Fixtures/payflow/money-vectors.json';
import {
    bpToPercent,
    decimalToMinor,
    formatMoney,
    invoiceTotals,
    lineAmounts,
    minorToDecimal,
    percentToBp,
} from '@/lib/pf/money';

describe('pf money', () => {
    it('computes line amounts like the server', () => {
        for (const line of vectors.lines) {
            expect(
                lineAmounts(
                    line.quantity,
                    line.unit_price_minor,
                    line.tax_rate_bp,
                ),
            ).toEqual({ amount: line.amount_minor, tax: line.tax_minor });
        }
    });

    it('computes invoice totals like the server', () => {
        for (const invoice of vectors.invoices) {
            const totals = invoiceTotals(
                invoice.lines.map((line) => ({
                    amount: line.amount_minor,
                    tax: line.tax_minor,
                    kind: line.kind as 'service' | 'late_fee',
                })),
            );

            expect(totals).toEqual({
                subtotal: invoice.subtotal_minor,
                tax: invoice.tax_minor,
                total: invoice.total_minor,
                lateFee: invoice.late_fee_applied_minor,
            });
        }
    });

    it('formats money like the server formatter', () => {
        for (const vector of formatVectors) {
            expect(
                formatMoney(
                    { minor: vector.minor, currency: vector.currency },
                    vector.locale as 'en' | 'ru',
                ),
            ).toBe(vector.expected);
        }
    });

    it('converts between decimal strings and minor units', () => {
        expect(decimalToMinor('1 200,5', 'USD')).toBe(120050);
        expect(decimalToMinor('12.345', 'EUR')).toBe(1234);
        expect(decimalToMinor('abc', 'USD')).toBeNull();
        expect(minorToDecimal(120050, 'USD')).toBe('1200.50');
        expect(minorToDecimal(5, 'USD')).toBe('0.05');
    });

    it('converts percent strings and basis points', () => {
        expect(percentToBp('20')).toBe(2000);
        expect(percentToBp('19,5')).toBe(1950);
        expect(percentToBp('0.07')).toBe(7);
        expect(bpToPercent(1950)).toBe('19.5');
        expect(bpToPercent(2000)).toBe('20');
        expect(bpToPercent(7)).toBe('0.07');
    });
});
