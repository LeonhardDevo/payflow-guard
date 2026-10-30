import { describe, expect, it } from 'vitest';
import {
    accountChanges,
    accountHistory,
    approvalSlots,
    formatRemaining,
    groupInFours,
    initials,
    markRequestText,
    remainingUntil,
    signalShare,
    sortSignals,
} from '@/lib/pf/guard';
import type { ApprovalRow, BankAccountView } from '@/types/payflow-guard';

const PIXELFORGE_REQUEST =
    "Hello Mark, due to an ongoing audit our company has moved to a new bank. Please make this week's payment urgently to the new account below — the old account is being closed today. The team is travelling, so please don't call the office; reply to this email instead. New details: PF Studio Payments UAB, IBAN LT58 3250 0677 4120 0519. Kind regards, Marta Nowak, PixelForge Studio. Mob. +370 600 41 977";

function marked(text: string): Array<[string, string]> {
    return markRequestText(text).flatMap((segment) =>
        segment.mark ? [[segment.mark, segment.text] as [string, string]] : [],
    );
}

describe('markRequestText', () => {
    it('highlights urgency and secrecy phrases in the seeded PixelForge request', () => {
        expect(marked(PIXELFORGE_REQUEST)).toEqual([
            ['secrecy', 'audit'],
            ['urgency', 'urgently'],
            ['urgency', 'today'],
            ['secrecy', 'travelling'],
            ['secrecy', "don't call"],
        ]);
    });

    it('keeps the original text intact', () => {
        const segments = markRequestText(PIXELFORGE_REQUEST);

        expect(segments.map((s) => s.text).join('')).toBe(PIXELFORGE_REQUEST);
    });

    it('respects word boundaries like the server keyword sets', () => {
        expect(marked('Please pay ASAP')).toEqual([['urgency', 'ASAP']]);
        expect(marked('todays plan')).toEqual([]);
        expect(marked('auditor report')).toEqual([]);
        expect(marked('I am travelling this week')).toEqual([
            ['secrecy', 'travelling'],
        ]);
    });

    it('normalises typographic apostrophes and the Russian letter yo', () => {
        expect(marked('Please don’t call me')).toEqual([
            ['secrecy', 'don’t call'],
        ]);
        expect(marked('Оплатите СРОЧНО, я в командировке')).toEqual([
            ['urgency', 'СРОЧНО'],
            ['secrecy', 'в командировке'],
        ]);
    });

    it('prefers the longest phrase when phrases overlap', () => {
        expect(marked('please pay as soon as possible')).toEqual([
            ['urgency', 'as soon as possible'],
        ]);
        expect(marked('не звонить и не обсуждайте')).toEqual([
            ['secrecy', 'не звонить'],
            ['secrecy', 'не обсуждайте'],
        ]);
    });

    it('returns no segments for empty text', () => {
        expect(markRequestText('')).toEqual([]);
    });
});

describe('countdown', () => {
    const now = Date.parse('2026-10-23T10:00:00Z');

    it('splits the remaining time into parts', () => {
        expect(remainingUntil('2026-10-24T12:30:15Z', now)).toEqual({
            total: 95_415,
            days: 1,
            hours: 2,
            minutes: 30,
            seconds: 15,
        });
    });

    it('returns null for missing, invalid or past targets', () => {
        expect(remainingUntil(null, now)).toBeNull();
        expect(remainingUntil('not a date', now)).toBeNull();
        expect(remainingUntil('2026-10-23T10:00:00Z', now)).toBeNull();
        expect(remainingUntil('2026-10-23T09:59:59Z', now)).toBeNull();
    });

    it('rounds partial seconds up so the countdown never shows zero early', () => {
        expect(remainingUntil('2026-10-23T10:00:00.200Z', now)?.total).toBe(1);
    });

    it('formats the two most significant units', () => {
        const calls: Array<[string, Record<string, number>]> = [];
        const translate = (key: string, values: Record<string, number>) => {
            calls.push([key, values]);

            return key;
        };
        const at = (iso: string) => remainingUntil(iso, now)!;

        formatRemaining(at('2026-10-25T13:00:00Z'), translate);
        formatRemaining(at('2026-10-23T15:45:30Z'), translate);
        formatRemaining(at('2026-10-23T10:12:05Z'), translate);
        formatRemaining(at('2026-10-23T10:00:42Z'), translate);

        expect(calls).toEqual([
            ['guard.countdown.days_hours', { days: 2, hours: 3 }],
            ['guard.countdown.hours_minutes', { hours: 5, minutes: 45 }],
            ['guard.countdown.minutes_seconds', { minutes: 12, seconds: 5 }],
            ['guard.countdown.seconds', { seconds: 42 }],
        ]);
    });
});

describe('signals', () => {
    it('sorts by points, then by code', () => {
        const sorted = sortSignals([
            { code: 'request_via_email', points: 10, params: {} },
            { code: 'lookalike_domain', points: 40, params: {} },
            { code: 'domain_mismatch', points: 10, params: {} },
        ]);

        expect(sorted.map((s) => s.code)).toEqual([
            'lookalike_domain',
            'domain_mismatch',
            'request_via_email',
        ]);
    });

    it('scales points to a 0–40 bar', () => {
        expect(signalShare(10)).toBe(25);
        expect(signalShare(40)).toBe(100);
        expect(signalShare(55)).toBe(100);
        expect(signalShare(-5)).toBe(0);
    });
});

function approval(
    id: number,
    userId: number,
    decision: 'approved' | 'rejected',
    createdAt: string,
    invalidated: string | null = null,
): ApprovalRow {
    return {
        id,
        user: { id: userId, name: `User ${userId}` },
        trusted_contact: null,
        decision,
        channel: 'web',
        comment: null,
        risk_band: 'medium',
        approval_round: 1,
        step_up_method: 'totp',
        created_at: createdAt,
        invalidated_at: invalidated,
    };
}

describe('approvalSlots', () => {
    it('fills decided slots in time order and marks the next open slot', () => {
        const slots = approvalSlots(
            {
                required_approvals: 2,
                status: 'pending_approval',
                awaiting_me: true,
                approvals: [
                    approval(5, 7, 'approved', '2026-10-23T09:00:00Z'),
                    approval(
                        3,
                        8,
                        'approved',
                        '2026-10-22T18:40:00Z',
                        '2026-10-23T08:12:00Z',
                    ),
                ],
            },
            9,
        );

        expect(slots.map((s) => [s.key, s.state, s.mine, s.next])).toEqual([
            ['a5', 'approved', false, false],
            ['w1', 'waiting', true, true],
        ]);
    });

    it('marks the viewer’s own decision and never invents an open slot after release', () => {
        const slots = approvalSlots(
            {
                required_approvals: 1,
                status: 'released',
                awaiting_me: false,
                approvals: [approval(1, 4, 'approved', '2026-10-20T10:00:00Z')],
            },
            4,
        );

        expect(slots).toHaveLength(1);
        expect(slots[0]).toMatchObject({ state: 'approved', mine: true });
    });

    it('shows a rejection and no next slot once the payout stopped', () => {
        const slots = approvalSlots(
            {
                required_approvals: 2,
                status: 'rejected',
                awaiting_me: false,
                approvals: [approval(2, 6, 'rejected', '2026-10-17T10:00:00Z')],
            },
            null,
        );

        expect(slots.map((s) => [s.state, s.next])).toEqual([
            ['rejected', false],
            ['waiting', false],
        ]);
    });

    it('returns no slots for an auto-approved payout', () => {
        expect(
            approvalSlots(
                {
                    required_approvals: 0,
                    status: 'approved',
                    awaiting_me: false,
                    approvals: [],
                },
                1,
            ),
        ).toEqual([]);
    });
});

describe('initials', () => {
    it('takes the first letters of the first and last words', () => {
        expect(initials('Dmitry Orlov')).toBe('DO');
        expect(initials('анна  смирнова')).toBe('АС');
        expect(initials('Mark')).toBe('M');
        expect(initials('  ')).toBe('?');
        expect(initials(null)).toBe('?');
    });
});

function account(
    overrides: Partial<BankAccountView> & Pick<BankAccountView, 'id'>,
): BankAccountView {
    return {
        version: 1,
        scheme: 'iban',
        holder_name: 'PixelForge Studio sp. z o.o.',
        bank_name: 'Vistula Demo Bank',
        bank_country: 'PL',
        currency: 'EUR',
        last4: '2874',
        display: 'PL61 •••• 2874',
        swift_bic_masked: null,
        status: 'verified',
        source: 'onboarding',
        requester_email_domain: null,
        risk_score: 0,
        risk_band: 'low',
        risk_signals: [],
        requested_by: { id: 1, name: 'Mark' },
        requested_at: '2026-02-26T09:00:00Z',
        verified_by: { id: 2, name: 'Dmitry' },
        verified_at: '2026-02-27T09:00:00Z',
        verification_method: 'callback_known_phone',
        verification_mode: 'standard',
        verification_note: null,
        cooling_off_until: null,
        rejected_by: null,
        rejected_at: null,
        rejection_reason: null,
        superseded_at: null,
        ...overrides,
    };
}

describe('account history', () => {
    const v1 = account({ id: 10 });
    const v2 = account({
        id: 11,
        version: 2,
        status: 'pending_verification',
        holder_name: 'PF Studio Payments UAB',
        bank_name: 'Neris Demo Bank',
        bank_country: 'LT',
        last4: '0519',
        display: 'LT58 •••• 0519',
    });

    it('lists only the fields that differ', () => {
        expect(accountChanges(v1, v2)).toEqual([
            {
                field: 'holder_name',
                before: 'PixelForge Studio sp. z o.o.',
                after: 'PF Studio Payments UAB',
            },
            {
                field: 'account',
                before: 'PL61 •••• 2874',
                after: 'LT58 •••• 0519',
            },
            {
                field: 'bank_name',
                before: 'Vistula Demo Bank',
                after: 'Neris Demo Bank',
            },
            { field: 'bank_country', before: 'PL', after: 'LT' },
        ]);
    });

    it('compares each version with the last accepted one before it', () => {
        const rejected = account({
            id: 12,
            version: 3,
            status: 'rejected',
            display: 'DE89 •••• 3000',
        });
        const history = accountHistory([v1, v2, rejected]);

        expect(
            history.map((h) => [h.account.id, h.previous?.id ?? null]),
        ).toEqual([
            [12, 10],
            [11, 10],
            [10, null],
        ]);
        expect(history[0].changes.map((c) => c.field)).toEqual(['account']);
        expect(history[2].changes.map((c) => c.field)).toEqual([
            'scheme',
            'holder_name',
            'account',
            'bank_name',
            'bank_country',
            'currency',
        ]);
    });
});

describe('groupInFours', () => {
    it('groups bank identifiers for reading aloud on a callback', () => {
        expect(groupInFours('EE537700771003819264')).toBe(
            'EE53 7700 7710 0381 9264',
        );
        expect(groupInFours('LT58 3250 0677 4120 0519')).toBe(
            'LT58 3250 0677 4120 0519',
        );
        expect(groupInFours('000123454471')).toBe('0001 2345 4471');
        expect(groupInFours('12345')).toBe('1234 5');
        expect(groupInFours(null)).toBe('');
    });
});
