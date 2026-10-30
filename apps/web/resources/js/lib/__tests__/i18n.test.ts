import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { I18n, loadLanguageAsync } from 'laravel-vue-i18n';
import { parseAll } from 'laravel-vue-i18n/loader';
import { beforeAll, describe, expect, it } from 'vitest';
import { has, humanize, isLocale, listIndices, t, tc, tOr } from '@/lib/i18n';

const here = path.dirname(fileURLToPath(import.meta.url));
const webRoot = path.resolve(here, '../../../..');
const langDir = path.join(webRoot, 'lang');
const sourceDir = path.join(webRoot, 'resources/js');

type Dictionary = Record<string, string>;

function loadDictionaries(): Record<'en' | 'ru', Dictionary> {
    const parsed = parseAll(langDir);
    const read = (locale: 'en' | 'ru'): Dictionary => ({
        ...parsed.find((file) => file.name === `php_${locale}.json`)
            ?.translations,
        ...(JSON.parse(
            fs.readFileSync(path.join(langDir, `${locale}.json`), 'utf8'),
        ) as Dictionary),
    });

    return { en: read('en'), ru: read('ru') };
}

const dictionaries = loadDictionaries();

const OWNED = /^(ui|marketing|roles)\./;

function owned(dictionary: Dictionary): string[] {
    return Object.keys(dictionary).filter((key) => OWNED.test(key));
}

function placeholders(message: string): string[] {
    return [...new Set(message.match(/:[a-z_]+/gi) ?? [])].sort();
}

function sourceFiles(dir: string): string[] {
    const skip = new Set(['actions', 'routes', 'wayfinder', '__tests__']);

    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);

        if (entry.isDirectory()) {
            return skip.has(entry.name) ? [] : sourceFiles(full);
        }

        return /\.(vue|ts)$/.test(entry.name) ? [full] : [];
    });
}

function referencedKeys(): string[] {
    const keys = new Set<string>();
    const call =
        /(?:\bt|\btc|\btOr|\$t|\bhas)\(\s*'((?:ui|marketing|roles)\.[a-z0-9_.-]+)'/g;
    const literal = /'((?:ui|marketing)\.[a-z0-9_]+(?:\.[a-z0-9_]+)+)'/g;

    for (const file of sourceFiles(sourceDir)) {
        const source = fs.readFileSync(file, 'utf8');

        for (const pattern of [call, literal]) {
            for (const match of source.matchAll(pattern)) {
                keys.add(match[1]);
            }
        }
    }

    keys.delete('marketing.faq.items');
    keys.delete('marketing.pricing.faq');

    return [...keys].sort();
}

const DYNAMIC_FAMILIES: Record<string, string[]> = {
    'ui.knowledge.status.%': ['queued', 'processing', 'indexed', 'failed'],
    'ui.knowledge.pipeline.%': ['uploaded', 'queued', 'processing', 'indexed'],
    'ui.knowledge.pipeline.%_hint': [
        'uploaded',
        'queued',
        'processing',
        'indexed',
    ],
    'ui.knowledge.pipeline.state_%': ['done', 'active', 'pending', 'failed'],
    'ui.knowledge.upload.errors.%': ['required', 'type', 'size', 'empty'],
    'ui.billing.status.%': [
        'free',
        'trialing',
        'active',
        'past_due',
        'canceled',
        'incomplete',
    ],
    'ui.history.channels.%': ['web', 'telegram'],
    'ui.integrations.telegram.steps.%': ['open', 'start', 'done'],
    'ui.integrations.telegram.features.%': ['alerts', 'ask', 'approve', 'lang'],
    'ui.dashboard.steps.%.title': ['upload', 'ask', 'invite', 'telegram'],
    'ui.dashboard.steps.%.body': ['upload', 'ask', 'invite', 'telegram'],
    'ui.checkout.card.%': ['number', 'expiry', 'cvc'],
    'ui.settings.language.hint_%': ['en', 'ru'],
    'ui.admin_ai.embeddings.%': ['local', 'openai-compatible', 'hash'],
    'roles.%': ['owner', 'admin', 'member'],
    'marketing.auth.cards.%.title': ['paid', 'reminder', 'guard'],
    'marketing.capabilities.%': [
        'reminders',
        'risk',
        'guard',
        'approvals',
        'telegram',
        'assistant',
        'audit',
        'bilingual',
    ],
    'marketing.hero.trust.%': ['free', 'no_card', 'bilingual'],
    'marketing.hero.mock.status.%': ['draft', 'sent', 'reminded', 'paid'],
    'marketing.hero.mock.hint.%': ['draft', 'sent', 'reminded', 'paid'],
    'marketing.problem.stats.%': ['overdue', 'owed', 'payroll'],
    'marketing.features.reminders.tones.%': ['friendly', 'firm', 'final'],
    'marketing.features.reminders.samples.%': ['friendly', 'firm', 'final'],
    'marketing.how.steps.%.title': ['connect', 'remind', 'predict', 'protect'],
    'marketing.how.steps.%.body': ['connect', 'remind', 'predict', 'protect'],
    'marketing.telegram.points.%': ['alerts', 'actions', 'ask', 'private'],
    'marketing.telegram.messages.%': [
        'overdue',
        'ask',
        'answer',
        'approval',
        'source',
    ],
    'marketing.telegram.buttons.%': [
        'send_firm',
        'snooze',
        'approve',
        'reject',
    ],
    'marketing.security.items.%.title': [
        'encryption',
        'audit',
        'approvals',
        'auth',
        'keys',
        'cdn',
    ],
    'marketing.security_page.pillars.%.title': [
        'data',
        'access',
        'payments',
        'ai',
        'infra',
        'audit',
    ],
    'ui.legal.docs.%': [
        'terms',
        'privacy',
        'cookies',
        'dpa',
        'subprocessors',
        'aup',
        'ai',
        'consent_ru',
    ],
    'marketing.legal.blurbs.%': [
        'terms',
        'privacy',
        'cookies',
        'dpa',
        'subprocessors',
        'aup',
        'ai',
        'consent_ru',
    ],
    'ui.settings.privacy.export_status.%': [
        'pending',
        'processing',
        'ready',
        'failed',
        'expired',
    ],
    'marketing.pricing_teaser.points.%': ['free', 'trial', 'cancel'],
};

function resolver(lang: string): Dictionary {
    const locale = lang.replace(/^php_/, '');

    if (lang.startsWith('php_')) {
        return {};
    }

    return dictionaries[locale as 'en' | 'ru'] ?? {};
}

beforeAll(() => {
    I18n.getSharedInstance(
        { lang: 'ru', fallbackLang: 'en', resolve: resolver },
        true,
    );
});

describe('translation files', () => {
    it('have the same UI keys in English and Russian', () => {
        const en = owned(dictionaries.en);
        const ru = owned(dictionaries.ru);

        expect(en.filter((key) => !(key in dictionaries.ru))).toEqual([]);
        expect(ru.filter((key) => !(key in dictionaries.en))).toEqual([]);
        expect(en.length).toBeGreaterThan(500);
    });

    it('use the same :placeholders in both locales', () => {
        const mismatched = owned(dictionaries.en).filter(
            (key) =>
                placeholders(dictionaries.en[key]).join() !==
                placeholders(dictionaries.ru[key] ?? '').join(),
        );

        expect(mismatched).toEqual([]);
    });

    it('contain every key referenced in the source code', () => {
        const missing = referencedKeys().flatMap((key) =>
            (['en', 'ru'] as const)
                .filter((locale) => !(key in dictionaries[locale]))
                .map((locale) => `${locale}:${key}`),
        );

        expect(missing).toEqual([]);
    });

    it('contain every runtime-built key', () => {
        const missing: string[] = [];

        for (const [pattern, values] of Object.entries(DYNAMIC_FAMILIES)) {
            for (const value of values) {
                const key = pattern.replace('%', value);

                for (const locale of ['en', 'ru'] as const) {
                    if (!(key in dictionaries[locale])) {
                        missing.push(`${locale}:${key}`);
                    }
                }
            }
        }

        expect(missing).toEqual([]);
    });

    it('use three plural forms for Russian pluralised strings', () => {
        const pluralKeys = owned(dictionaries.en).filter((key) =>
            dictionaries.en[key].includes('|'),
        );

        expect(pluralKeys.length).toBeGreaterThan(5);

        for (const key of pluralKeys) {
            expect(dictionaries.ru[key].split('|'), key).toHaveLength(3);
        }
    });
});

describe('i18n helpers', () => {
    it('translate with replacements (numbers are stringified)', () => {
        expect(
            t('ui.pagination.showing', { from: 1, to: 25, total: 140 }),
        ).toBe('Показаны 1–25 из 140');
        expect(t('ui.members.remove_named', { name: 'Анна' })).toBe(
            'Исключить Анна',
        );
    });

    it('applies Russian plural rules (1 / 2–4 / 5+ and the 11–14 exception)', () => {
        const cases: Array<[number, string]> = [
            [1, '1 фрагмент'],
            [2, '2 фрагмента'],
            [4, '4 фрагмента'],
            [5, '5 фрагментов'],
            [11, '11 фрагментов'],
            [12, '12 фрагментов'],
            [21, '21 фрагмент'],
            [22, '22 фрагмента'],
            [25, '25 фрагментов'],
            [101, '101 фрагмент'],
            [111, '111 фрагментов'],
        ];

        for (const [count, expected] of cases) {
            expect(tc('ui.knowledge.chunks', count)).toBe(expected);
        }

        expect(tc('ui.members.count', 3)).toBe('3 участника');
        expect(tc('ui.audit.changes', 7)).toBe('7 изменений');
    });

    it('detects missing keys and falls back', () => {
        expect(has('ui.common.save')).toBe(true);
        expect(has('ui.common.does_not_exist')).toBe(false);
        expect(tOr('ui.common.does_not_exist', 'Fallback')).toBe('Fallback');
        expect(tOr('roles.owner', 'Owner')).toBe('Владелец');
    });

    it('lists array-backed translations', () => {
        expect(
            listIndices('marketing.faq.items', 'q').length,
        ).toBeGreaterThanOrEqual(6);
        expect(listIndices('marketing.nothing.here', 'q')).toEqual([]);
    });

    it('switches language at runtime', async () => {
        await loadLanguageAsync('en');

        expect(tc('ui.knowledge.chunks', 1)).toBe('1 chunk');
        expect(tc('ui.knowledge.chunks', 2)).toBe('2 chunks');

        await loadLanguageAsync('ru');
    });

    it('humanizes action identifiers and validates locales', () => {
        expect(humanize('member.role_changed')).toBe('Member role changed');
        expect(isLocale('ru')).toBe(true);
        expect(isLocale('de')).toBe(false);
    });
});
