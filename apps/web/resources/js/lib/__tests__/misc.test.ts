import { HttpCancelledError, HttpResponseError } from '@inertiajs/core';
import { describe, expect, it } from 'vitest';
import {
    actionDomain,
    actionTone,
    diffRows,
    formatAuditValue,
    groupByDay,
} from '@/lib/audit';
import { endpoints, withQuery } from '@/lib/endpoints';
import { describeHttpError } from '@/lib/http-errors';
import { normalizeModels } from '@/lib/models';
import { pageItems, visibleRange } from '@/lib/pagination';
import { currencyName, timezoneOffset, timezoneOptions } from '@/lib/regional';
import {
    botHandle,
    formatCountdown,
    isTelegramDeepLink,
    secondsLeft,
} from '@/lib/telegram';
import { refName } from '@/lib/utils';

describe('endpoints', () => {
    it('builds contract URLs with methods', () => {
        expect(endpoints.knowledge.show(12)).toEqual({
            url: '/knowledge/12',
            method: 'get',
        });
        expect(endpoints.settings.members.remove(3)).toEqual({
            url: '/settings/members/3',
            method: 'delete',
        });
        expect(endpoints.admin.ai.key('opencode-zen').url).toBe(
            '/admin/ai/keys/opencode-zen',
        );
        expect(endpoints.locale('ru')).toEqual({
            url: '/locale/ru',
            method: 'post',
        });
        expect(endpoints.onboarding.create().url).toBe(
            '/onboarding/workspace?new=1',
        );
    });

    it('appends only non-empty query values', () => {
        expect(
            withQuery('/knowledge', {
                q: 'refund',
                status: null,
                page: 2,
                empty: '',
            }),
        ).toBe('/knowledge?q=refund&page=2');
        expect(withQuery('/x?a=1', { b: true })).toBe('/x?a=1&b=true');
        expect(withQuery('/x', { roles: ['admin', 'member'] })).toBe(
            '/x?roles%5B%5D=admin&roles%5B%5D=member',
        );
        expect(withQuery('/x', {})).toBe('/x');
    });
});

describe('pagination', () => {
    const links = [
        { url: null, label: '&laquo; Previous', active: false },
        { url: '/x?page=1', label: '1', active: true },
        { url: '/x?page=2', label: '2', active: false },
        { url: null, label: '...', active: false },
        { url: '/x?page=9', label: '9', active: false },
        { url: '/x?page=2', label: 'Next &raquo;', active: false },
    ];

    it('normalises Laravel paginator links', () => {
        const parts = pageItems(links);

        expect(parts.previous).toBeNull();
        expect(parts.next).toBe('/x?page=2');
        expect(
            parts.items.map((item) => (item.type === 'page' ? item.page : '…')),
        ).toEqual([1, 2, '…', 9]);
        expect(pageItems([])).toEqual({
            previous: null,
            next: null,
            items: [],
        });
    });

    it('computes the visible row range', () => {
        expect(
            visibleRange(
                { current_page: 2, last_page: 3, per_page: 25, total: 60 },
                25,
            ),
        ).toEqual({ from: 26, to: 50 });
        expect(
            visibleRange(
                { current_page: 3, last_page: 3, per_page: 25, total: 60 },
                10,
            ),
        ).toEqual({ from: 51, to: 60 });
        expect(
            visibleRange(
                { current_page: 1, last_page: 1, per_page: 25, total: 0 },
                0,
            ),
        ).toEqual({ from: 0, to: 0 });
    });
});

describe('telegram helpers', () => {
    const now = Date.parse('2026-10-22T12:00:00Z');

    it('counts down to the link expiry', () => {
        expect(secondsLeft('2026-10-22T12:15:00Z', now)).toBe(900);
        expect(secondsLeft('2026-10-22T11:00:00Z', now)).toBe(0);
        expect(secondsLeft(null, now)).toBe(0);
        expect(formatCountdown(900)).toBe('15:00');
        expect(formatCountdown(65)).toBe('01:05');
    });

    it('formats bot handles and validates deep links', () => {
        expect(botHandle('payflow_bot')).toBe('@payflow_bot');
        expect(botHandle('@x')).toBe('@x');
        expect(botHandle(null)).toBeNull();
        expect(isTelegramDeepLink('https://t.me/payflow_bot?start=abc')).toBe(
            true,
        );
        expect(isTelegramDeepLink('tg://resolve?domain=x')).toBe(true);
        expect(isTelegramDeepLink('javascript:alert(1)')).toBe(false);
        expect(isTelegramDeepLink('https://evil.example/t.me')).toBe(false);
    });
});

describe('audit helpers', () => {
    it('maps actions to tones and domains', () => {
        expect(actionTone('member.removed')).toBe('destructive');
        expect(actionTone('invitation.created')).toBe('success');
        expect(actionTone('auth.login')).toBe('warning');
        expect(actionTone('workspace.updated')).toBe('info');
        expect(actionTone('something.else')).toBe('neutral');
        expect(actionDomain('document.uploaded')).toBe('document');
    });

    it('formats diff values', () => {
        const labels = { empty: 'empty', yes: 'Yes', no: 'No' };

        expect(formatAuditValue(null, labels)).toBe('empty');
        expect(formatAuditValue(true, labels)).toBe('Yes');
        expect(formatAuditValue(['a', 'b'], labels)).toBe('a, b');
        expect(formatAuditValue({ a: 1 }, labels)).toBe('{"a":1}');
        expect(diffRows({ role: ['member', 'admin'] })).toEqual([
            { field: 'role', before: 'member', after: 'admin' },
        ]);
        expect(diffRows(null)).toEqual([]);
    });

    it('groups consecutive entries by day', () => {
        const groups = groupByDay(
            [
                { created_at: '2026-10-22T10:00:00Z' },
                { created_at: '2026-10-22T08:00:00Z' },
                { created_at: '2026-10-21T08:00:00Z' },
            ],
            (iso) => iso.slice(0, 10),
        );

        expect(groups.map((group) => [group.day, group.items.length])).toEqual([
            ['2026-10-22', 2],
            ['2026-10-21', 1],
        ]);
    });
});

describe('model list normalisation', () => {
    it('accepts bare lists, objects and the ai-service envelope', () => {
        expect(
            normalizeModels(['a', 'b', 'a']).models.map((model) => model.id),
        ).toEqual(['a', 'b']);
        expect(
            normalizeModels({
                provider: 'openrouter',
                source: 'catalog',
                error: 'timeout',
                models: [{ id: 'x', free: true }, 'y'],
            }),
        ).toEqual({
            models: [{ id: 'x', free: true }, { id: 'y' }],
            source: 'catalog',
            error: 'timeout',
        });
        expect(normalizeModels(null)).toEqual({
            models: [],
            source: null,
            error: null,
        });
    });
});

describe('http error description', () => {
    it('extracts translated server messages', () => {
        const error = new HttpResponseError('fail', {
            status: 402,
            data: '{"message":"Limit reached","upgrade":true}',
            headers: {},
        });

        expect(describeHttpError(error)).toMatchObject({
            kind: 'response',
            status: 402,
            message: 'Limit reached',
        });
    });

    it('drops the framework’s untranslated texts for generic statuses', () => {
        for (const status of [404, 405, 419, 500]) {
            const error = new HttpResponseError('fail', {
                status,
                data: '{"message":"The route assistant/actions could not be found."}',
                headers: {},
            });

            expect(describeHttpError(error)).toMatchObject({
                kind: 'response',
                status,
                message: null,
            });
        }
    });

    it('recognises cancellation and network failures', () => {
        expect(describeHttpError(new HttpCancelledError()).kind).toBe(
            'cancelled',
        );
        expect(describeHttpError(new Error('offline')).kind).toBe('network');
        expect(
            describeHttpError(
                new HttpResponseError('x', {
                    status: 500,
                    data: 'oops',
                    headers: {},
                }),
            ).message,
        ).toBeNull();
    });
});

describe('regional helpers', () => {
    it('describes timezones and currencies', () => {
        expect(
            timezoneOffset('Europe/Moscow', new Date('2026-01-01T00:00:00Z')),
        ).toBe('GMT+3');
        expect(timezoneOffset('Not/AZone')).toBe('');
        expect(timezoneOptions(['America/New_York'])[0]).toMatchObject({
            value: 'America/New_York',
            label: 'America/New York',
        });
        expect(currencyName('EUR', 'en')).toBe('Euro');
        expect(currencyName('RUB', 'ru')).toBe('российский рубль');
    });

    it('reads names from references or strings', () => {
        const ref: { id: number; name: string } = {
            id: 1,
            name: 'Demo Studio',
        };

        expect(refName(ref)).toBe('Demo Studio');
        expect(refName('Plain')).toBe('Plain');
        expect(refName(null)).toBe('');
    });
});
