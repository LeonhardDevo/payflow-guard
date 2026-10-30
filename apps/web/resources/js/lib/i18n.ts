import {
    getActiveLanguage,
    loadLanguageAsync,
    trans,
    transChoice,
} from 'laravel-vue-i18n';
import type { Locale } from '@/types/shared';

export type Replacements = Record<string, string | number | null | undefined>;

export const SUPPORTED_LOCALES: readonly Locale[] = ['en', 'ru'] as const;

export const INTL_LOCALE: Record<Locale, string> = {
    en: 'en-US',
    ru: 'ru-RU',
};

function normalize(replacements?: Replacements): Record<string, string> {
    const out: Record<string, string> = {};

    if (!replacements) {
        return out;
    }

    for (const [key, value] of Object.entries(replacements)) {
        out[key] = value === null || value === undefined ? '' : String(value);
    }

    return out;
}

export function t(key: string, replacements?: Replacements): string {
    return trans(key, normalize(replacements));
}

export function tc(
    key: string,
    count: number,
    replacements?: Replacements,
): string {
    return transChoice(key, count, normalize({ count, ...replacements }));
}

export function has(key: string): boolean {
    return trans(key) !== key;
}

export function tOr(
    key: string,
    fallback: string,
    replacements?: Replacements,
): string {
    const value = t(key, replacements);

    return value === key ? fallback : value;
}

export function listIndices(prefix: string, probe: string): number[] {
    const indices: number[] = [];

    for (let index = 0; index < 50; index++) {
        if (!has(`${prefix}.${index}.${probe}`)) {
            break;
        }

        indices.push(index);
    }

    return indices;
}

export function humanize(value: string): string {
    const words = value
        .replace(/[._-]+/g, ' ')
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .trim()
        .toLowerCase();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

export function isLocale(value: unknown): value is Locale {
    return (
        typeof value === 'string' &&
        (SUPPORTED_LOCALES as readonly string[]).includes(value)
    );
}

export function activeLocale(): Locale {
    const lang = getActiveLanguage();

    return isLocale(lang) ? lang : 'en';
}

export async function applyLocale(locale: Locale): Promise<void> {
    if (getActiveLanguage() !== locale) {
        await loadLanguageAsync(locale);
    }

    if (typeof document !== 'undefined') {
        document.documentElement.lang = locale;
    }
}
