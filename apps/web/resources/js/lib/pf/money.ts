import { INTL_LOCALE } from '@/lib/i18n';
import type { Locale } from '@/types/shared';
import type { CurrencyCode, Money } from '@/types/payflow';

const digitsCache = new Map<string, number>();

export function fractionDigits(currency: CurrencyCode): number {
    const cached = digitsCache.get(currency);

    if (cached !== undefined) {
        return cached;
    }

    let digits = 2;

    try {
        digits =
            new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency,
            }).resolvedOptions().maximumFractionDigits ?? 2;
    } catch {
        digits = 2;
    }

    digitsCache.set(currency, digits);

    return digits;
}

export function toMajor(minor: number, currency: CurrencyCode): number {
    return minor / 10 ** fractionDigits(currency);
}

export function formatMoney(
    money: Money | null | undefined,
    locale: Locale,
    options: { compact?: boolean } = {},
): string {
    if (!money) {
        return '—';
    }

    const value = toMajor(money.minor, money.currency);

    try {
        return new Intl.NumberFormat(INTL_LOCALE[locale] ?? 'en-US', {
            style: 'currency',
            currency: money.currency,
            notation: options.compact ? 'compact' : 'standard',
            maximumFractionDigits: options.compact ? 1 : undefined,
        }).format(value);
    } catch {
        return `${value.toFixed(fractionDigits(money.currency))} ${money.currency}`;
    }
}

export function minorToDecimal(minor: number, currency: CurrencyCode): string {
    const digits = fractionDigits(currency);
    const sign = minor < 0 ? '-' : '';
    const abs = Math.abs(Math.trunc(minor));

    if (digits === 0) {
        return `${sign}${abs}`;
    }

    const base = 10 ** digits;

    return `${sign}${Math.floor(abs / base)}.${String(abs % base).padStart(digits, '0')}`;
}

export function normalizeDecimal(
    input: string | number | null | undefined,
): string {
    if (input === null || input === undefined) {
        return '';
    }

    return String(input)
        .trim()
        .replace(/[\s  ']/g, '')
        .replace(',', '.');
}

export function decimalToMinor(
    input: string | number | null | undefined,
    currency: CurrencyCode,
): number | null {
    const value = normalizeDecimal(input);
    const digits = fractionDigits(currency);
    const match = /^(\d+)(?:\.(\d*))?$/.exec(value);

    if (!match) {
        return null;
    }

    const fraction = (match[2] ?? '').slice(0, digits).padEnd(digits, '0');

    return Number(match[1]) * 10 ** digits + (digits ? Number(fraction) : 0);
}

export function percentToBp(input: string | number | null | undefined): number {
    const value = normalizeDecimal(input);
    const match = /^(\d{1,3})(?:\.(\d{0,2}))?$/.exec(value);

    if (!match) {
        return 0;
    }

    return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
}

export function bpToPercent(bp: number | null | undefined): string {
    if (bp === null || bp === undefined) {
        return '';
    }

    const whole = Math.floor(bp / 100);
    const rest = bp % 100;

    return rest === 0
        ? String(whole)
        : `${whole}.${String(rest).padStart(2, '0').replace(/0$/, '')}`;
}

export function lineAmounts(
    quantity: string,
    unitPriceMinor: number,
    taxRateBp: number,
): { amount: number; tax: number } {
    const qty = Number(normalizeDecimal(quantity));

    if (!Number.isFinite(qty) || qty <= 0 || unitPriceMinor < 0) {
        return { amount: 0, tax: 0 };
    }

    const qtyMilli = Math.round(qty * 1000);
    const amount = Math.floor((qtyMilli * unitPriceMinor + 500) / 1000);
    const tax = Math.floor((amount * Math.max(0, taxRateBp) + 5000) / 10000);

    return { amount, tax };
}

export function invoiceTotals(
    lines: Array<{
        amount: number;
        tax: number;
        kind?: 'service' | 'late_fee';
    }>,
): { subtotal: number; tax: number; total: number; lateFee: number } {
    let subtotal = 0;
    let tax = 0;
    let lateFee = 0;

    for (const line of lines) {
        subtotal += line.amount;
        tax += line.tax;

        if (line.kind === 'late_fee') {
            lateFee += line.amount + line.tax;
        }
    }

    return { subtotal, tax, total: subtotal + tax, lateFee };
}

export function money(minor: number, currency: CurrencyCode): Money {
    return { minor, currency };
}
