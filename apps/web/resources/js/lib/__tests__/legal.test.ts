import { describe, expect, it } from 'vitest';
import {
    COOKIE_NOTICE_COOKIE,
    cookieNoticeCookie,
    cookieNoticeDismissed,
    legalDocLabelKey,
    PUBLIC_LEGAL_DOCS,
    retentionChoices,
    timeLeft,
} from '@/lib/legal';

describe('legal helpers', () => {
    it('lists every public document of PLATFORM.md §10 except the RU consent', () => {
        expect(PUBLIC_LEGAL_DOCS).toEqual([
            'terms',
            'privacy',
            'cookies',
            'dpa',
            'subprocessors',
            'aup',
            'ai',
        ]);
    });

    it('maps document keys to translation keys', () => {
        expect(legalDocLabelKey('terms')).toBe('ui.legal.docs.terms');
        expect(legalDocLabelKey('consent-ru')).toBe('ui.legal.docs.consent_ru');
    });

    it('detects the dismissed cookie notice', () => {
        expect(cookieNoticeDismissed('')).toBe(false);
        expect(cookieNoticeDismissed('appearance=dark; locale=ru')).toBe(false);
        expect(
            cookieNoticeDismissed(`appearance=dark; ${COOKIE_NOTICE_COOKIE}=1`),
        ).toBe(true);
        expect(cookieNoticeDismissed(`x_${COOKIE_NOTICE_COOKIE}=1`)).toBe(
            false,
        );
    });

    it('builds a first-party, long-lived, Lax cookie (Secure on https)', () => {
        const plain = cookieNoticeCookie(false);

        expect(plain).toContain(`${COOKIE_NOTICE_COOKIE}=1`);
        expect(plain).toContain('path=/');
        expect(plain).toContain('SameSite=Lax');
        expect(plain).not.toContain('Secure');
        expect(cookieNoticeCookie(true)).toContain('Secure');
    });

    it('computes the remaining grace period', () => {
        const now = new Date('2026-10-22T12:00:00Z');

        expect(timeLeft(null, now)).toBeNull();
        expect(timeLeft('not-a-date', now)).toBeNull();
        expect(timeLeft('2026-11-05T12:00:00Z', now)).toEqual({
            days: 14,
            hours: 0,
            expired: false,
        });
        expect(timeLeft('2026-10-22T17:30:00Z', now)).toEqual({
            days: 0,
            hours: 5,
            expired: false,
        });
        expect(timeLeft('2026-10-21T12:00:00Z', now)).toEqual({
            days: 0,
            hours: 0,
            expired: true,
        });
    });

    it('offers sorted retention choices within the bounds, always including the current value', () => {
        expect(retentionChoices(45, { min: 30, max: 90 })).toEqual([
            30, 45, 60, 90,
        ]);
        expect(retentionChoices(180, { min: 7, max: 180 })).toEqual([
            7, 14, 30, 60, 90, 180,
        ]);
        expect(retentionChoices(365)).toContain(365);
        expect(retentionChoices(0, { min: 30, max: 30 })).toEqual([30]);
    });
});
