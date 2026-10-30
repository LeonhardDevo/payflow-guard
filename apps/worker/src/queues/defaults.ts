import type { JobsOptions } from 'bullmq';

export const DEFAULT_JOB_OPTIONS: JobsOptions = Object.freeze({
  attempts: 5,
  backoff: { type: 'exponential', delay: 2000 },
  removeOnComplete: 1000,
  removeOnFail: 5000,
});

export function telegramUpdateJobId(updateId: number): string {
  return `tg-update-${updateId}`;
}
