import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseAll } from 'laravel-vue-i18n/loader';
import { describe, expect, it } from 'vitest';

const here = path.dirname(fileURLToPath(import.meta.url));
const webRoot = path.resolve(here, '../../../..');
const langDir = path.join(webRoot, 'lang');
const jsDir = path.join(webRoot, 'resources/js');
const enumDir = path.join(webRoot, 'app/Domain/Guard/Enums');

type Dictionary = Record<string, string>;

const parsed = parseAll(langDir);
const dictionary = (locale: 'en' | 'ru'): Dictionary =>
    parsed.find((file) => file.name === `php_${locale}.json`)?.translations ??
    {};
const en = dictionary('en');
const ru = dictionary('ru');

const guardKeys = (dict: Dictionary) =>
    Object.keys(dict)
        .filter((key) => key.startsWith('guard.'))
        .sort();

const GUARD_FILES = [
    'pages/guard',
    'pages/vendors',
    'pages/payouts',
    'pages/trusted',
    'pages/settings/Guard.vue',
    'components/pf/guard',
    'composables/pf/useCountdown.ts',
    'lib/pf/guard.ts',
].flatMap((entry) => {
    const full = path.join(jsDir, entry);

    return fs.statSync(full).isDirectory()
        ? fs
              .readdirSync(full)
              .filter((name) => /\.(vue|ts)$/.test(name))
              .map((name) => path.join(full, name))
        : [full];
});

function enumValues(name: string): string[] {
    const source = fs.readFileSync(path.join(enumDir, `${name}.php`), 'utf8');

    return [...source.matchAll(/case \w+ = '([a-z_]+)';/g)].map((m) => m[1]);
}

function placeholders(message: string): string[] {
    return [...new Set(message.match(/:[a-z_]+/gi) ?? [])].sort();
}

describe('Payment Guard translations', () => {
    it('have the same keys in English and Russian', () => {
        expect(guardKeys(en).length).toBeGreaterThan(400);
        expect(guardKeys(ru)).toEqual(guardKeys(en));
    });

    it('use the same placeholders in both locales', () => {
        for (const key of guardKeys(en)) {
            expect(placeholders(ru[key]), key).toEqual(placeholders(en[key]));
        }
    });

    it('contain no emoji or pictographs', () => {
        const pictograph = /\p{Extended_Pictographic}/u;

        for (const key of guardKeys(en)) {
            expect(pictograph.test(en[key]), key).toBe(false);
            expect(pictograph.test(ru[key]), key).toBe(false);
        }
    });

    it('use two English and three Russian plural forms', () => {
        const plural = guardKeys(en).filter((key) => en[key].includes('|'));

        expect(plural.length).toBeGreaterThan(0);

        for (const key of plural) {
            expect(en[key].split('|'), key).toHaveLength(2);
            expect(ru[key].split('|'), key).toHaveLength(3);
        }
    });

    it('cover every key the Guard screens reference', () => {
        const literal = /(?<!can\()['`](guard\.[a-z0-9_.]+)['`]/g;
        const template = /`(guard\.[a-z0-9_.]*)\$\{/g;
        const missing: string[] = [];
        const keys = guardKeys(en);

        for (const file of GUARD_FILES) {
            const source = fs.readFileSync(file, 'utf8');

            for (const [, key] of source.matchAll(literal)) {
                if (!(key in en)) {
                    missing.push(`${path.basename(file)}: ${key}`);
                }
            }

            for (const [, prefix] of source.matchAll(template)) {
                if (!keys.some((key) => key.startsWith(prefix))) {
                    missing.push(`${path.basename(file)}: ${prefix}*`);
                }
            }
        }

        expect(missing).toEqual([]);
    });

    it('label every value of the backend enums', () => {
        const families: Array<[string, string[]]> = [
            ['guard.alerts.%', enumValues('AlertType')],
            ['guard.severity.%', enumValues('AlertSeverity')],
            ['guard.alert_status.%', [...enumValues('AlertStatus'), 'active']],
            ['guard.bank.status.%', enumValues('BankAccountStatus')],
            ['guard.bank.source.%', enumValues('BankChangeSource')],
            ['guard.bank.scheme.%', enumValues('BankScheme')],
            ['guard.bank.methods.%', enumValues('VerificationMethod')],
            ['guard.hold_reason.%', enumValues('PayoutHoldReason')],
            ['guard.hold_reason_long.%', enumValues('PayoutHoldReason')],
            ['guard.payout_status.%', enumValues('PayoutStatus')],
            ['guard.vendor_status.%', enumValues('VendorStatus')],
            ['guard.vendor_category.%', enumValues('VendorCategory')],
            [
                'guard.stepup.methods.%',
                enumValues('StepUpMethod').filter((m) => m !== 'none'),
            ],
            ['guard.trusted_status.%', enumValues('TrustedConfirmationStatus')],
            ['guard.trusted.done.%', enumValues('TrustedConfirmationStatus')],
            ['guard.trusted.subject.%', enumValues('TrustedSubjectType')],
            [
                'guard.settings.contact_status.%',
                enumValues('TrustedContactStatus'),
            ],
            ['guard.payout.channel.%', enumValues('ApprovalChannel')],
            ['guard.payout.decision.%', enumValues('ApprovalDecision')],
            [
                'guard.trusted.verify.%.title',
                ['verified', 'declined', 'decline_prompt', 'invalid'],
            ],
            [
                'guard.payout.cannot_approve.%',
                [
                    'is_requester',
                    'already_decided',
                    'no_permission',
                    'not_pending',
                    'details_changed',
                    'not_eligible_yet',
                    'second_factor_missing',
                ],
            ],
            [
                'guard.payout.cannot_release.%',
                [
                    'releaser_is_requester',
                    'release_delayed',
                    'not_approved',
                    'no_permission',
                ],
            ],
            [
                'guard.review.cannot.%',
                [
                    'is_requester',
                    'no_permission',
                    'not_pending',
                    'not_eligible_yet',
                    'second_factor_missing',
                ],
            ],
        ];
        const missing: string[] = [];

        for (const [family, values] of families) {
            expect(values.length, family).toBeGreaterThan(0);

            for (const value of values) {
                const key = family.replace('%', value);

                if (!(key in en)) {
                    missing.push(key);
                }
            }
        }

        expect(missing).toEqual([]);
    });

    it('describe every risk signal the server can produce', () => {
        const serverSignals = Object.keys(en)
            .filter((key) => key.startsWith('payflow.signals.'))
            .map((key) => key.slice('payflow.signals.'.length));

        expect(serverSignals.length).toBeGreaterThan(10);

        for (const code of serverSignals) {
            expect(en, code).toHaveProperty([`guard.signals.${code}`]);
        }
    });

    it('never promise that a payment is safe', () => {
        const absolute =
            /\b(guaranteed safe|100% safe|fraud-proof|always safe)\b|гарантированно безопас/iu;

        for (const key of guardKeys(en)) {
            expect(absolute.test(en[key]), key).toBe(false);
            expect(absolute.test(ru[key]), key).toBe(false);
        }
    });
});
