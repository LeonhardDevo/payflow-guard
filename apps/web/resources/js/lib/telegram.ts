export function secondsLeft(
    expiresAt: string | null | undefined,
    now: number = Date.now(),
): number {
    if (!expiresAt) {
        return 0;
    }

    const target = new Date(expiresAt).getTime();

    if (Number.isNaN(target)) {
        return 0;
    }

    return Math.max(0, Math.floor((target - now) / 1000));
}

export function formatCountdown(seconds: number): string {
    const safe = Math.max(0, Math.floor(seconds));
    const minutes = Math.floor(safe / 60);
    const rest = safe % 60;

    return `${String(minutes).padStart(2, '0')}:${String(rest).padStart(2, '0')}`;
}

export function botHandle(username: string | null | undefined): string | null {
    if (!username) {
        return null;
    }

    return username.startsWith('@') ? username : `@${username}`;
}

export function isTelegramDeepLink(url: string): boolean {
    try {
        const parsed = new URL(url);

        return (
            (parsed.protocol === 'https:' &&
                (parsed.hostname === 't.me' ||
                    parsed.hostname === 'telegram.me')) ||
            parsed.protocol === 'tg:'
        );
    } catch {
        return false;
    }
}
