import type { Queue } from 'bullmq';
import type { Logger } from '../logger.js';
import { JOB_NAMES } from './names.js';

export const PRIVACY_PURGE_SCHEDULER_ID = 'privacy-purge-daily';

export const BILLING_RENEWALS_SCHEDULER_ID = 'billing-renewals';

export const PAYMENTS_RECONCILE_SCHEDULER_ID = 'payments-reconcile';

export type SchedulerQueue = Pick<Queue, 'upsertJobScheduler' | 'removeJobScheduler'>;

export async function scheduleRepeatable(
  queue: SchedulerQueue,
  schedulerId: string,
  jobName: string,
  cron: string | null,
  logger: Logger,
): Promise<void> {
  if (cron === null) {
    await queue.removeJobScheduler(schedulerId);
    logger.info({ schedulerId }, 'scheduler disabled');
    return;
  }
  await queue.upsertJobScheduler(
    schedulerId,
    { pattern: cron, tz: 'UTC' },
    {
      name: jobName,
      data: { scheduled: true },
      opts: { attempts: 5, backoff: { type: 'exponential', delay: 60_000 }, removeOnComplete: 30, removeOnFail: 100 },
    },
  );
  logger.info({ schedulerId, cron, tz: 'UTC' }, 'job scheduled');
}

export async function schedulePrivacyPurge(queue: SchedulerQueue, cron: string | null, logger: Logger): Promise<void> {
  await scheduleRepeatable(queue, PRIVACY_PURGE_SCHEDULER_ID, JOB_NAMES.privacyPurge, cron, logger);
}

export async function scheduleBilling(
  queue: SchedulerQueue,
  crons: { renewals: string | null; reconcile: string | null },
  logger: Logger,
): Promise<void> {
  await scheduleRepeatable(queue, BILLING_RENEWALS_SCHEDULER_ID, JOB_NAMES.billingRenewals, crons.renewals, logger);
  await scheduleRepeatable(queue, PAYMENTS_RECONCILE_SCHEDULER_ID, JOB_NAMES.paymentsReconcile, crons.reconcile, logger);
}
