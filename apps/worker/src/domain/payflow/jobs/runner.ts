import { randomUUID } from 'node:crypto';
import { setTimeout as sleep } from 'node:timers/promises';
import type { Job, Queue } from 'bullmq';
import type { Logger } from '../../../logger.js';
import type { JobOutcome } from '../../../queues/workers.js';
import { PF_SCHEDULERS, SCHEDULER_JOB_OPTIONS, type SchedulerJob } from './definitions.js';
import type { PayflowJobs, SchedulerRunResult } from './handlers.js';

export type SchedulerQueueLike = Pick<Queue, 'upsertJobScheduler' | 'removeJobScheduler'>;

export async function registerPayflowSchedulers(queue: SchedulerQueueLike, logger: Logger, enabled = true): Promise<void> {
  for (const scheduler of PF_SCHEDULERS) {
    if (!enabled) {
      await queue.removeJobScheduler(scheduler.id);
      continue;
    }
    await queue.upsertJobScheduler(
      scheduler.id,
      { pattern: scheduler.cron, tz: 'UTC' },
      { name: scheduler.job, data: { scheduled: true }, opts: SCHEDULER_JOB_OPTIONS },
    );
  }
  logger.info({ schedulers: PF_SCHEDULERS.map((scheduler) => scheduler.id), enabled }, 'payflow schedulers registered');
}

export class JobActivity {
  private readonly counts = new Map<string, { completed: number; failed: number }>();

  record(queue: string, outcome: JobOutcome): void {
    if (outcome === 'retrying') return;
    const current = this.counts.get(queue) ?? { completed: 0, failed: 0 };
    current[outcome]++;
    this.counts.set(queue, current);
  }

  snapshot(queues: readonly string[]): { completed: number; failed: number } {
    let completed = 0;
    let failed = 0;
    for (const queue of queues) {
      const entry = this.counts.get(queue);
      completed += entry?.completed ?? 0;
      failed += entry?.failed ?? 0;
    }
    return { completed, failed };
  }
}

export type DrainQueue = Pick<Queue, 'name' | 'getJobCounts' | 'getDelayed'>;

export function isSchedulerIteration(job: Pick<Job, 'id' | 'repeatJobKey'>): boolean {
  return Boolean(job.repeatJobKey) || (job.id ?? '').startsWith('repeat:');
}

export interface DrainOptions {
  queues: readonly string[];
  promoteDelayed: boolean;
  timeoutMs: number;
  pollMs?: number;
}

export interface DrainResult {
  processed: number;
  failed: number;
  idle: boolean;
}

export const MAX_DRAIN_TIMEOUT_MS = 30_000;

export class DevJobRunner {
  constructor(
    private readonly jobs: PayflowJobs,
    private readonly queues: (name: string) => DrainQueue | null,
    private readonly activity: JobActivity,
    private readonly logger: Logger,
  ) {}

  async run(job: SchedulerJob, now: string | null): Promise<SchedulerRunResult> {
    const requestId = randomUUID();
    const logger = this.logger.child({ job, requestId, now });
    const result = await this.jobs.runScheduler(job, now, { requestId, logger });
    logger.info({ enqueued: result.enqueued }, 'dev job run');
    return result;
  }

  async drain(options: DrainOptions): Promise<DrainResult> {
    const queues = options.queues.map((name) => {
      const queue = this.queues(name);
      if (!queue) throw new UnknownQueueError(name);
      return queue;
    });
    const before = this.activity.snapshot(options.queues);
    const deadline = Date.now() + Math.min(options.timeoutMs, MAX_DRAIN_TIMEOUT_MS);
    const pollMs = options.pollMs ?? 50;
    let idleRounds = 0;
    let idle = false;
    while (Date.now() < deadline) {
      const delayed = options.promoteDelayed ? await this.promote(queues) : 0;
      const pending = delayed + (await this.pending(queues));
      idleRounds = pending === 0 ? idleRounds + 1 : 0;
      if (idleRounds >= 2) {
        idle = true;
        break;
      }
      await sleep(pollMs);
    }
    const after = this.activity.snapshot(options.queues);
    const failed = after.failed - before.failed;
    return { processed: after.completed - before.completed + failed, failed, idle };
  }

  private async promote(queues: readonly DrainQueue[]): Promise<number> {
    let promoted = 0;
    for (const queue of queues) {
      for (const job of await queue.getDelayed(0, 999)) {
        if (isSchedulerIteration(job)) continue;
        await job.promote().catch(() => undefined);
        promoted++;
      }
    }
    return promoted;
  }

  private async pending(queues: readonly DrainQueue[]): Promise<number> {
    const counts = await Promise.all(queues.map((queue) => queue.getJobCounts('waiting', 'active', 'prioritized', 'waiting-children')));
    return counts.reduce((sum, entry) => sum + Object.values(entry).reduce((inner, value) => inner + value, 0), 0);
  }
}

export class UnknownQueueError extends Error {
  constructor(readonly queue: string) {
    super(`unknown queue "${queue}"`);
    this.name = 'UnknownQueueError';
  }
}
