import { INTL_LOCALE } from '@/lib/i18n';
import type { Locale } from '@/types/shared';

export type RegionalOption = {
    value: string;
    label: string;
    hint?: string | null;
};

export function timezoneOffset(
    timeZone: string,
    at: Date = new Date(),
): string {
    try {
        const parts = new Intl.DateTimeFormat('en-US', {
            timeZone,
            timeZoneName: 'shortOffset',
        }).formatToParts(at);

        return parts.find((part) => part.type === 'timeZoneName')?.value ?? '';
    } catch {
        return '';
    }
}

export function timezoneOptions(
    timezones: string[],
    at: Date = new Date(),
): RegionalOption[] {
    return timezones.map((zone) => ({
        value: zone,
        label: zone.replace(/_/g, ' '),
        hint: timezoneOffset(zone, at) || null,
    }));
}

export function currencyName(code: string, locale: Locale): string {
    try {
        const names = new Intl.DisplayNames([INTL_LOCALE[locale]], {
            type: 'currency',
        });

        return names.of(code) ?? code;
    } catch {
        return code;
    }
}

export function currencyOptions(
    codes: string[],
    locale: Locale,
): RegionalOption[] {
    return codes.map((code) => ({
        value: code,
        label: currencyName(code, locale),
        hint: code,
    }));
}
