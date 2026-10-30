import type { AuditLog } from '@/types/models';

export type AuditTone =
    | 'success'
    | 'warning'
    | 'destructive'
    | 'info'
    | 'ai'
    | 'neutral';

const TONE_RULES: Array<[RegExp, AuditTone]> = [
    [
        /(deleted|removed|revoked|destroyed|unlinked|canceled|cancelled|failed|blocked|denied)$/,
        'destructive',
    ],
    [
        /(created|invited|joined|linked|accepted|uploaded|enabled|activated|paid|approved)$/,
        'success',
    ],
    [/(login|logout|password|two_factor|2fa|passkey|key|secret)/, 'warning'],
    [/^(ai|assistant|admin\.ai)/, 'ai'],
    [/(updated|changed|switched|renamed|reembed|role)/, 'info'],
];

export function actionTone(action: string): AuditTone {
    const normalized = action.toLowerCase();

    for (const [pattern, tone] of TONE_RULES) {
        if (pattern.test(normalized)) {
            return tone;
        }
    }

    return 'neutral';
}

export function actionDomain(action: string): string {
    return action.split('.')[0] ?? action;
}

export function formatAuditValue(
    value: unknown,
    labels: { empty: string; yes: string; no: string },
): string {
    if (value === null || value === undefined || value === '') {
        return labels.empty;
    }

    if (typeof value === 'boolean') {
        return value ? labels.yes : labels.no;
    }

    if (typeof value === 'number' || typeof value === 'string') {
        return String(value);
    }

    if (Array.isArray(value)) {
        return (
            value.map((item) => formatAuditValue(item, labels)).join(', ') ||
            labels.empty
        );
    }

    if (typeof value === 'bigint') {
        return value.toString();
    }

    try {
        return JSON.stringify(value) ?? labels.empty;
    } catch {
        return labels.empty;
    }
}

export type DiffRow = { field: string; before: unknown; after: unknown };

export function diffRows(changes: AuditLog['changes']): DiffRow[] {
    if (!changes) {
        return [];
    }

    return Object.entries(changes).map(([field, pair]) => ({
        field,
        before: Array.isArray(pair) ? pair[0] : undefined,
        after: Array.isArray(pair) ? pair[1] : pair,
    }));
}

export function groupByDay<T extends { created_at: string }>(
    items: T[],
    dayKey: (iso: string) => string,
): Array<{ day: string; items: T[] }> {
    const groups: Array<{ day: string; items: T[] }> = [];

    for (const item of items) {
        const day = dayKey(item.created_at);
        const last = groups[groups.length - 1];

        if (last && last.day === day) {
            last.items.push(item);
        } else {
            groups.push({ day, items: [item] });
        }
    }

    return groups;
}
