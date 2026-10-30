export const CLIENT_MAIL_BACKOFF = 'pf-client-mail';

export const CLIENT_MAIL_DELAYS_S: readonly number[] = Object.freeze([60, 300, 900, 3600, 10_800, 21_600, 43_200]);

export const CLIENT_MAIL_ATTEMPTS = CLIENT_MAIL_DELAYS_S.length + 1;

export function clientMailDelayMs(attemptsMade: number): number {
  const index = Math.min(Math.max(attemptsMade, 1), CLIENT_MAIL_DELAYS_S.length) - 1;
  return (CLIENT_MAIL_DELAYS_S[index] ?? 0) * 1000;
}

export function customBackoff(attemptsMade: number, type?: string): number {
  if (type === CLIENT_MAIL_BACKOFF) return clientMailDelayMs(attemptsMade);
  return Math.min(2000 * 2 ** Math.max(attemptsMade - 1, 0), 3_600_000);
}
