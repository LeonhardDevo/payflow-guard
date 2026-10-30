import type {
    ApprovalRow,
    BankAccountView,
    PayoutDetail,
    Signal,
} from '@/types/payflow-guard';

export const URGENCY_PHRASES: readonly string[] = [
    'urgent',
    'urgently',
    'asap',
    'immediately',
    'today',
    'right away',
    'as soon as possible',
    'deadline',
    'within the hour',
    'срочно',
    'срочный',
    'немедленно',
    'сегодня же',
    'как можно скорее',
    'в течение часа',
    'безотлагательно',
];

export const SECRECY_PHRASES: readonly string[] = [
    'confidential',
    'do not call',
    "don't call",
    'no calls',
    'keep this between',
    'discreet',
    'audit',
    'travelling',
    'traveling',
    "can't talk",
    'cannot talk',
    'конфиденциально',
    'не звоните',
    'не звонить',
    'не обсуждайте',
    'в командировке',
    'аудит',
    'проверка',
    'не могу говорить',
];

export type TextMark = 'urgency' | 'secrecy';
export type TextSegment = { text: string; mark: TextMark | null };

type Range = { start: number; end: number; mark: TextMark };

function normalizeForSearch(text: string): string {
    let out = '';

    for (const char of text) {
        const mapped =
            char === '’' ? "'" : char === 'ё' || char === 'Ё' ? 'е' : char;
        const lower = mapped.toLowerCase();
        out += lower.length === mapped.length ? lower : mapped;
    }

    return out;
}

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function findRanges(
    haystack: string,
    phrases: readonly string[],
    mark: TextMark,
): Range[] {
    const ranges: Range[] = [];

    for (const phrase of phrases) {
        const pattern = new RegExp(
            `(?<![\\p{L}\\p{N}])${escapeRegExp(normalizeForSearch(phrase))}(?![\\p{L}\\p{N}])`,
            'gu',
        );

        for (const match of haystack.matchAll(pattern)) {
            const start = match.index ?? 0;
            ranges.push({ start, end: start + match[0].length, mark });
        }
    }

    return ranges;
}

export function markRequestText(text: string): TextSegment[] {
    if (!text) {
        return [];
    }

    const haystack = normalizeForSearch(text);
    const candidates = [
        ...findRanges(haystack, SECRECY_PHRASES, 'secrecy'),
        ...findRanges(haystack, URGENCY_PHRASES, 'urgency'),
    ].sort((a, b) => a.start - b.start || b.end - a.end);

    const chosen: Range[] = [];

    for (const range of candidates) {
        const last = chosen[chosen.length - 1];

        if (!last || range.start >= last.end) {
            chosen.push(range);
        }
    }

    const segments: TextSegment[] = [];
    let cursor = 0;

    for (const range of chosen) {
        if (range.start > cursor) {
            segments.push({
                text: text.slice(cursor, range.start),
                mark: null,
            });
        }

        segments.push({
            text: text.slice(range.start, range.end),
            mark: range.mark,
        });
        cursor = range.end;
    }

    if (cursor < text.length) {
        segments.push({ text: text.slice(cursor), mark: null });
    }

    return segments;
}

export type Remaining = {
    total: number;
    days: number;
    hours: number;
    minutes: number;
    seconds: number;
};

export function remainingUntil(
    target: string | null | undefined,
    now: number,
): Remaining | null {
    if (!target) {
        return null;
    }

    const end = Date.parse(target);

    if (Number.isNaN(end) || end <= now) {
        return null;
    }

    const total = Math.ceil((end - now) / 1000);

    return {
        total,
        days: Math.floor(total / 86_400),
        hours: Math.floor((total % 86_400) / 3_600),
        minutes: Math.floor((total % 3_600) / 60),
        seconds: total % 60,
    };
}

export type Translate = (
    key: string,
    replacements: Record<string, number>,
) => string;

export function formatRemaining(
    remaining: Remaining,
    translate: Translate,
): string {
    const { days, hours, minutes, seconds } = remaining;

    if (days > 0) {
        return translate('guard.countdown.days_hours', { days, hours });
    }

    if (hours > 0) {
        return translate('guard.countdown.hours_minutes', { hours, minutes });
    }

    if (minutes > 0) {
        return translate('guard.countdown.minutes_seconds', {
            minutes,
            seconds,
        });
    }

    return translate('guard.countdown.seconds', { seconds });
}

export function sortSignals(signals: readonly Signal[]): Signal[] {
    return [...signals].sort(
        (a, b) => b.points - a.points || a.code.localeCompare(b.code),
    );
}

export const SIGNAL_SCALE = 40;

export function signalShare(points: number): number {
    return Math.max(0, Math.min(100, (points / SIGNAL_SCALE) * 100));
}

export type ApprovalSlotState = 'approved' | 'rejected' | 'waiting';

export type ApprovalSlot = {
    key: string;
    state: ApprovalSlotState;
    approval: ApprovalRow | null;
    mine: boolean;
    next: boolean;
};

type SlotSource = Pick<
    PayoutDetail,
    'required_approvals' | 'approvals' | 'status' | 'awaiting_me'
>;

export function approvalSlots(
    payout: SlotSource,
    viewerId: number | null,
): ApprovalSlot[] {
    const decided = payout.approvals
        .filter((approval) => approval.invalidated_at === null)
        .sort(
            (a, b) =>
                Date.parse(a.created_at) - Date.parse(b.created_at) ||
                a.id - b.id,
        );
    const total = Math.max(payout.required_approvals, decided.length);
    const slots: ApprovalSlot[] = [];
    const open = payout.status === 'pending_approval';
    let nextAssigned = false;

    for (let index = 0; index < total; index++) {
        const approval = decided[index] ?? null;

        if (approval) {
            slots.push({
                key: `a${approval.id}`,
                state: approval.decision,
                approval,
                mine: viewerId !== null && approval.user?.id === viewerId,
                next: false,
            });

            continue;
        }

        const next: boolean = open && !nextAssigned;
        nextAssigned = nextAssigned || next;
        slots.push({
            key: `w${index}`,
            state: 'waiting',
            approval: null,
            mine: next && payout.awaiting_me,
            next,
        });
    }

    return slots;
}

export function initials(name: string | null | undefined): string {
    if (!name) {
        return '?';
    }

    const parts = name
        .trim()
        .split(/\s+/u)
        .filter((part) => /[\p{L}\p{N}]/u.test(part));

    if (!parts.length) {
        return '?';
    }

    const first = leadingChar(parts[0]);
    const last = parts.length > 1 ? leadingChar(parts[parts.length - 1]) : '';

    return (first + last).toUpperCase();
}

function leadingChar(word: string): string {
    const code = word.codePointAt(0);

    return code === undefined ? '' : String.fromCodePoint(code);
}

export type AccountField =
    | 'scheme'
    | 'holder_name'
    | 'account'
    | 'bank_name'
    | 'bank_country'
    | 'currency'
    | 'swift_bic';

export type AccountChange = {
    field: AccountField;
    before: string | null;
    after: string | null;
};

function accountValue(
    account: BankAccountView,
    field: AccountField,
): string | null {
    switch (field) {
        case 'account':
            return account.display;
        case 'swift_bic':
            return account.swift_bic_masked;
        default:
            return account[field];
    }
}

const ACCOUNT_FIELDS: readonly AccountField[] = [
    'scheme',
    'holder_name',
    'account',
    'bank_name',
    'bank_country',
    'currency',
    'swift_bic',
];

export function accountChanges(
    previous: BankAccountView | null,
    next: BankAccountView,
): AccountChange[] {
    return ACCOUNT_FIELDS.flatMap((field) => {
        const before = previous ? accountValue(previous, field) : null;
        const after = accountValue(next, field);

        return before === after ? [] : [{ field, before, after }];
    });
}

export function accountHistory(accounts: readonly BankAccountView[]): Array<{
    account: BankAccountView;
    previous: BankAccountView | null;
    changes: AccountChange[];
}> {
    const ordered = [...accounts].sort((a, b) => b.version - a.version);

    return ordered.map((account, index) => {
        const previous =
            ordered
                .slice(index + 1)
                .find(
                    (candidate) =>
                        candidate.status === 'verified' ||
                        candidate.status === 'superseded',
                ) ?? null;

        return {
            account,
            previous,
            changes: accountChanges(previous, account),
        };
    });
}

export function groupInFours(value: string | null | undefined): string {
    return (value ?? '').replace(/\s+/g, '').replace(/(.{4})(?=.)/g, '$1 ');
}
