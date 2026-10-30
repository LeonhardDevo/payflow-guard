import { describe, expect, it } from 'vitest';
import { createFormatter, toDate } from '@/lib/format';

const norm = (value: string) => value.replace(/[  ]/g, ' ');

describe('createFormatter', () => {
    const en = createFormatter({
        locale: 'en',
        currency: 'USD',
        timeZone: 'UTC',
    });
    const ru = createFormatter({
        locale: 'ru',
        currency: 'RUB',
        timeZone: 'Europe/Moscow',
    });

    it('formats money per locale and currency', () => {
        expect(en.money(1234.5)).toBe('$1,234.50');
        expect(en.money(49)).toBe('$49');
        expect(norm(ru.money(1234.5))).toBe('1 234,50 ₽');
        expect(norm(ru.money(10, 'USD'))).toBe('10 $');
    });

    it('supports compact money and explicit fraction digits', () => {
        expect(en.money(17_700, 'USD', { compact: true })).toBe('$17.7K');
        expect(en.money(0.1234, 'USD', { fractionDigits: 4 })).toBe('$0.1234');
    });

    it('returns an em dash for missing values', () => {
        expect(en.money(null)).toBe('—');
        expect(en.number(undefined)).toBe('—');
        expect(en.date('not a date')).toBe('—');
        expect(en.relative(null)).toBe('—');
    });

    it('formats numbers, percents and compact numbers', () => {
        expect(en.number(1234567)).toBe('1,234,567');
        expect(norm(ru.number(1234567))).toBe('1 234 567');
        expect(en.percent(0.593)).toBe('59%');
        expect(en.percent(0.1234, 1)).toBe('12.3%');
        expect(en.compact(15300)).toBe('15.3K');
    });

    it('formats dates in the workspace timezone', () => {
        const instant = '2026-10-22T21:30:00Z';

        expect(en.date(instant, 'medium')).toBe('Oct 22, 2026');
        expect(norm(ru.date(instant, 'medium'))).toBe('23 окт. 2026 г.');
        expect(norm(ru.time(instant))).toBe('00:30');
    });

    it('falls back to the browser timezone when the workspace timezone is invalid', () => {
        const broken = createFormatter({
            locale: 'en',
            currency: 'USD',
            timeZone: 'Mars/Olympus',
        });

        expect(broken.timeZone).toBeUndefined();
        expect(broken.date('2026-01-15T12:00:00Z')).toContain('2026');
    });

    it('formats relative time with sensible units', () => {
        const now = new Date('2026-10-22T12:00:00Z');

        expect(en.relative('2026-10-22T11:59:50Z', now)).toBe('now');
        expect(en.relative('2026-10-22T11:55:00Z', now)).toBe('5 minutes ago');
        expect(en.relative('2026-10-21T12:00:00Z', now)).toBe('yesterday');
        expect(en.relative('2026-10-25T12:00:00Z', now)).toBe('in 3 days');
        expect(ru.relative('2026-10-22T09:00:00Z', now)).toBe('3 часа назад');
        expect(ru.relative('2026-10-17T12:00:00Z', now)).toBe('5 дней назад');
    });

    it('formats byte sizes and durations', () => {
        expect(en.bytes(512)).toBe('512 B');
        expect(en.bytes(0)).toBe('0 B');
        expect(en.bytes(1536)).toBe('1.5 kB');
        expect(en.bytes(5 * 1024 * 1024)).toBe('5 MB');
        expect(en.duration(850)).toBe('850 ms');
        expect(en.duration(2340)).toBe('2.3 sec');
    });
});

describe('toDate', () => {
    it('parses strings, numbers and dates', () => {
        expect(toDate('2026-10-22T00:00:00Z')?.toISOString()).toBe(
            '2026-10-22T00:00:00.000Z',
        );
        expect(toDate(0)?.getTime()).toBe(0);
        expect(toDate(new Date('invalid'))).toBeNull();
        expect(toDate('')).toBeNull();
    });
});
