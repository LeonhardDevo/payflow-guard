import { INTL_LOCALE } from '@/lib/i18n';
import type { Locale } from '@/types/shared';

export type FormatContext = {
    locale: Locale;
    currency: string;
    timeZone?: string | null;
};

export type DateInput = string | number | Date | null | undefined;

export type DateStyle = 'short' | 'medium' | 'long' | 'full';

const RELATIVE_UNITS: Array<[Intl.RelativeTimeFormatUnit, number]> = [
    ['year', 60 * 60 * 24 * 365],
    ['month', 60 * 60 * 24 * 30],
    ['week', 60 * 60 * 24 * 7],
    ['day', 60 * 60 * 24],
    ['hour', 60 * 60],
    ['minute', 60],
    ['second', 1],
];

export function toDate(value: DateInput): Date | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const date = value instanceof Date ? value : new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

function safeTimeZone(timeZone?: string | null): string | undefined {
    if (!timeZone) {
        return undefined;
    }

    try {
        new Intl.DateTimeFormat('en-US', { timeZone });

        return timeZone;
    } catch {
        return undefined;
    }
}

export type Formatter = ReturnType<typeof createFormatter>;

export function createFormatter(context: FormatContext) {
    const intlLocale = INTL_LOCALE[context.locale] ?? 'en-US';
    const timeZone = safeTimeZone(context.timeZone);

    function number(
        value: number | null | undefined,
        options: Intl.NumberFormatOptions = {},
    ): string {
        if (value === null || value === undefined || Number.isNaN(value)) {
            return '—';
        }

        return new Intl.NumberFormat(intlLocale, options).format(value);
    }

    function money(
        amount: number | null | undefined,
        currency: string = context.currency,
        options: { compact?: boolean; fractionDigits?: number } = {},
    ): string {
        if (amount === null || amount === undefined || Number.isNaN(amount)) {
            return '—';
        }

        const fractionDigits =
            options.fractionDigits ?? (Number.isInteger(amount) ? 0 : 2);

        try {
            return new Intl.NumberFormat(intlLocale, {
                style: 'currency',
                currency: currency || 'USD',
                notation: options.compact ? 'compact' : 'standard',
                minimumFractionDigits: options.compact ? 0 : fractionDigits,
                maximumFractionDigits: options.compact ? 1 : fractionDigits,
            }).format(amount);
        } catch {
            return `${number(amount)} ${currency}`;
        }
    }

    function compact(value: number | null | undefined): string {
        return number(value, { notation: 'compact', maximumFractionDigits: 1 });
    }

    function percent(
        ratio: number | null | undefined,
        fractionDigits = 0,
    ): string {
        return number(ratio, {
            style: 'percent',
            minimumFractionDigits: fractionDigits,
            maximumFractionDigits: fractionDigits,
        });
    }

    function date(value: DateInput, style: DateStyle = 'medium'): string {
        const parsed = toDate(value);

        if (!parsed) {
            return '—';
        }

        return new Intl.DateTimeFormat(intlLocale, {
            dateStyle: style,
            timeZone,
        }).format(parsed);
    }

    function dateTime(value: DateInput, style: DateStyle = 'medium'): string {
        const parsed = toDate(value);

        if (!parsed) {
            return '—';
        }

        return new Intl.DateTimeFormat(intlLocale, {
            dateStyle: style,
            timeStyle: 'short',
            timeZone,
        }).format(parsed);
    }

    function time(value: DateInput): string {
        const parsed = toDate(value);

        if (!parsed) {
            return '—';
        }

        return new Intl.DateTimeFormat(intlLocale, {
            timeStyle: 'short',
            timeZone,
        }).format(parsed);
    }

    function dayLabel(value: DateInput): string {
        const parsed = toDate(value);

        if (!parsed) {
            return '';
        }

        return new Intl.DateTimeFormat(intlLocale, {
            day: 'numeric',
            month: 'short',
            timeZone,
        }).format(parsed);
    }

    function relative(value: DateInput, now: Date = new Date()): string {
        const parsed = toDate(value);

        if (!parsed) {
            return '—';
        }

        const diffSeconds = Math.round(
            (parsed.getTime() - now.getTime()) / 1000,
        );
        const absolute = Math.abs(diffSeconds);
        const formatter = new Intl.RelativeTimeFormat(intlLocale, {
            numeric: 'auto',
        });

        if (absolute < 45) {
            return formatter.format(0, 'second');
        }

        for (const [unit, seconds] of RELATIVE_UNITS) {
            if (absolute >= seconds || unit === 'second') {
                return formatter.format(
                    Math.round(diffSeconds / seconds),
                    unit,
                );
            }
        }

        return formatter.format(diffSeconds, 'second');
    }

    function bytes(value: number | null | undefined): string {
        if (value === null || value === undefined || Number.isNaN(value)) {
            return '—';
        }

        const units = [
            'byte',
            'kilobyte',
            'megabyte',
            'gigabyte',
            'terabyte',
        ] as const;
        let size = Math.max(0, value);
        let index = 0;

        while (size >= 1024 && index < units.length - 1) {
            size /= 1024;
            index++;
        }

        if (index === 0) {
            return `${number(size, { maximumFractionDigits: 0 })} ${context.locale === 'ru' ? 'Б' : 'B'}`;
        }

        return new Intl.NumberFormat(intlLocale, {
            style: 'unit',
            unit: units[index],
            unitDisplay: 'short',
            maximumFractionDigits: index === 0 ? 0 : 1,
        }).format(size);
    }

    function duration(milliseconds: number | null | undefined): string {
        if (
            milliseconds === null ||
            milliseconds === undefined ||
            Number.isNaN(milliseconds)
        ) {
            return '—';
        }

        if (milliseconds < 1000) {
            return new Intl.NumberFormat(intlLocale, {
                style: 'unit',
                unit: 'millisecond',
                unitDisplay: 'short',
                maximumFractionDigits: 0,
            }).format(milliseconds);
        }

        return new Intl.NumberFormat(intlLocale, {
            style: 'unit',
            unit: 'second',
            unitDisplay: 'short',
            maximumFractionDigits: 1,
        }).format(milliseconds / 1000);
    }

    return {
        locale: context.locale,
        intlLocale,
        currency: context.currency,
        timeZone,
        number,
        money,
        compact,
        percent,
        date,
        dateTime,
        time,
        dayLabel,
        relative,
        bytes,
        duration,
    };
}

export function detectTimeZone(): string {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    } catch {
        return 'UTC';
    }
}
