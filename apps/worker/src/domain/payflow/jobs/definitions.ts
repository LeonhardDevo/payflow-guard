import type { JobsOptions } from 'bullmq';
import { QUEUES } from '../../../queues/names.js';

export const PF_JOBS = {
  remindersPlanAll: 'reminders:plan-all',
  remindersPlan: 'reminders:plan',
  remindersDispatch: 'reminders:dispatch',
  riskRecomputeAll: 'risk:recompute-all',
  riskRecompute: 'risk:recompute',
  digestsRun: 'digests:run',
  paymentsSyncAll: 'payments:sync-all',
  paymentsSync: 'payments:sync',
  guardHourly: 'guard:hourly',
  maintenancePurge: 'maintenance:purge',
  importsProcess: 'imports:process',
} as const;

export const IMPORT_TOPIC = 'payflow.import.requested';

export type SchedulerJob =
  | typeof PF_JOBS.remindersPlanAll
  | typeof PF_JOBS.riskRecomputeAll
  | typeof PF_JOBS.digestsRun
  | typeof PF_JOBS.paymentsSyncAll
  | typeof PF_JOBS.guardHourly
  | typeof PF_JOBS.maintenancePurge;

export interface SchedulerDefinition {
  id: string;
  job: SchedulerJob;
  cron: string;
}

export const PF_SCHEDULERS: readonly SchedulerDefinition[] = Object.freeze([
  { id: 'pf-reminders-plan', job: PF_JOBS.remindersPlanAll, cron: '*/15 * * * *' },
  { id: 'pf-risk-recompute', job: PF_JOBS.riskRecomputeAll, cron: '15 2 * * *' },
  { id: 'pf-digests', job: PF_JOBS.digestsRun, cron: '0 * * * *' },
  { id: 'pf-payments-sync', job: PF_JOBS.paymentsSyncAll, cron: '*/30 * * * *' },
  { id: 'pf-guard-hourly', job: PF_JOBS.guardHourly, cron: '5 * * * *' },
  { id: 'pf-maintenance-purge', job: PF_JOBS.maintenancePurge, cron: '30 3 * * *' },
]);

export const SCHEDULER_JOB_OPTIONS: Omit<JobsOptions, 'jobId'> = Object.freeze({
  attempts: 3,
  backoff: { type: 'exponential', delay: 5000 },
  removeOnComplete: 100,
  removeOnFail: 500,
});

export const PLAN_JOB_OPTIONS: Omit<JobsOptions, 'jobId'> = Object.freeze({ attempts: 5, backoff: { type: 'exponential', delay: 2000 } });

export const DISPATCH_ATTEMPTS = 8;
export const MAX_TOO_EARLY_RETRIES = 5;

export function dispatchJobOptions(delayMs: number): Omit<JobsOptions, 'jobId'> {
  return {
    attempts: DISPATCH_ATTEMPTS,
    backoff: { type: 'exponential', delay: 30_000 },
    removeOnComplete: true,
    removeOnFail: true,
    delay: Math.max(0, Math.round(delayMs)),
  };
}

export const RISK_JOB_OPTIONS: Omit<JobsOptions, 'jobId'> = Object.freeze({ attempts: 5, backoff: { type: 'exponential', delay: 2000 } });

export const SYNC_JOB_OPTIONS: Omit<JobsOptions, 'jobId'> = Object.freeze({ attempts: 5, backoff: { type: 'exponential', delay: 10_000 } });

export const PLAN_TICK_MS = 15 * 60_000;
export const SYNC_TICK_MS = 30 * 60_000;
export const SYNC_CANDIDATE_LIMIT = 100;
export const IMPORT_BATCH_SIZE = 200;

export const PF_QUEUE_OF = {
  [PF_JOBS.remindersPlanAll]: QUEUES.pfScheduler,
  [PF_JOBS.riskRecomputeAll]: QUEUES.pfScheduler,
  [PF_JOBS.digestsRun]: QUEUES.pfScheduler,
  [PF_JOBS.paymentsSyncAll]: QUEUES.pfScheduler,
  [PF_JOBS.guardHourly]: QUEUES.pfScheduler,
  [PF_JOBS.maintenancePurge]: QUEUES.pfScheduler,
  [PF_JOBS.remindersPlan]: QUEUES.pfReminders,
  [PF_JOBS.remindersDispatch]: QUEUES.pfReminders,
  [PF_JOBS.riskRecompute]: QUEUES.pfAnalytics,
  [PF_JOBS.paymentsSync]: QUEUES.pfPayments,
  [PF_JOBS.importsProcess]: QUEUES.pfImports,
} as const;

export const PF_QUEUES = [QUEUES.pfScheduler, QUEUES.pfReminders, QUEUES.pfAnalytics, QUEUES.pfPayments, QUEUES.pfImports] as const;

export function epochSeconds(instant: string | number): number {
  const ms = typeof instant === 'number' ? instant : Date.parse(instant);
  if (!Number.isFinite(ms)) throw new Error(`invalid instant: ${String(instant)}`);
  return Math.floor(ms / 1000);
}

export function tickEpoch(nowMs: number, tickMs: number): number {
  return Math.floor(nowMs / tickMs) * (tickMs / 1000);
}

export const jobIds = {
  plan: (workspaceId: number, tick: number): string => `plan-${workspaceId}-${tick}`,
  dispatch: (reminderId: number, scheduledFor: string): string => `rem-${reminderId}-${epochSeconds(scheduledFor)}`,
  dispatchRetry: (reminderId: number, scheduledFor: string, attempt: number): string =>
    `rem-${reminderId}-${epochSeconds(scheduledFor)}-r${attempt}`,
  risk: (workspaceId: number, localDate: string): string => `risk-${workspaceId}-${localDate.replace(/-/g, '')}`,
  sync: (paymentLinkId: number, tick: number): string => `sync-${paymentLinkId}-${tick}`,
};

export function localDate(nowMs: number, timezone: string): string {
  try {
    return new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(nowMs);
  } catch {
    return new Date(nowMs).toISOString().slice(0, 10);
  }
}
