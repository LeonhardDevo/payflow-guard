import type { LucideIcon } from '@lucide/vue';
import {
    Bot,
    Cookie,
    FileText,
    Gavel,
    Handshake,
    Server,
    ShieldCheck,
    Signature,
} from '@lucide/vue';
import type { LegalDocKey } from '@/types/legal';

export const PUBLIC_LEGAL_DOCS = [
    'terms',
    'privacy',
    'cookies',
    'dpa',
    'subprocessors',
    'aup',
    'ai',
] as const satisfies readonly LegalDocKey[];

const ICONS: Record<string, LucideIcon> = {
    terms: Gavel,
    privacy: ShieldCheck,
    cookies: Cookie,
    dpa: Handshake,
    subprocessors: Server,
    aup: FileText,
    ai: Bot,
    'consent-ru': Signature,
};

export function legalDocIcon(key: string): LucideIcon {
    return ICONS[key] ?? FileText;
}

export function legalDocLabelKey(key: string): string {
    return `ui.legal.docs.${key.replace(/-/g, '_')}`;
}

export const COOKIE_NOTICE_COOKIE = 'cookie_notice';

const COOKIE_NOTICE_MAX_AGE = 60 * 60 * 24 * 365;

export function cookieNoticeDismissed(cookie: string): boolean {
    return cookie
        .split(';')
        .some((part) => part.trim().startsWith(`${COOKIE_NOTICE_COOKIE}=`));
}

export function cookieNoticeCookie(secure: boolean): string {
    return [
        `${COOKIE_NOTICE_COOKIE}=1`,
        'path=/',
        `max-age=${COOKIE_NOTICE_MAX_AGE}`,
        'SameSite=Lax',
        ...(secure ? ['Secure'] : []),
    ].join('; ');
}

export function timeLeft(
    target: string | Date | null | undefined,
    now: Date,
): { days: number; hours: number; expired: boolean } | null {
    if (!target) {
        return null;
    }

    const date = target instanceof Date ? target : new Date(target);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const ms = Math.max(0, date.getTime() - now.getTime());
    const totalHours = Math.floor(ms / 3_600_000);

    return {
        days: Math.floor(totalHours / 24),
        hours: totalHours % 24,
        expired: ms === 0,
    };
}

const RETENTION_STEPS = [7, 14, 30, 60, 90, 180, 365, 730, 1095, 1825, 2555];

export function retentionChoices(
    current: number,
    bounds?: { min: number; max: number } | null,
): number[] {
    const min = bounds?.min ?? 1;
    const max = bounds?.max ?? 3650;
    const values = [
        ...RETENTION_STEPS.filter((value) => value >= min && value <= max),
        ...(bounds ? [bounds.min, bounds.max] : []),
        current,
    ];

    return [...new Set(values)]
        .filter((value) => Number.isFinite(value) && value > 0)
        .sort((a, b) => a - b);
}
