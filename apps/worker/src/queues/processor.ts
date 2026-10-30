import { randomUUID } from 'node:crypto';
import { UnrecoverableError, type Job } from 'bullmq';
import type { HandlerMap, JobContext } from '../jobs/types.js';
import { errorFields, type Logger } from '../logger.js';
import type { Metrics } from '../metrics.js';
import { DEFAULT_JOB_OPTIONS } from './defaults.js';

export function createProcessor(
  queue: string,
  handlers: HandlerMap,
  deps: { logger: Logger; metrics?: Metrics },
): (job: Job) => Promise<unknown> {
  return async (job: Job) => {
    const handler = handlers[job.name];
    const maxAttempts = job.opts.attempts ?? DEFAULT_JOB_OPTIONS.attempts ?? 1;
    const attempt = job.attemptsMade + 1;
    const requestId = randomUUID();
    const logger = deps.logger.child({ queue, job: job.name, jobId: job.id, attempt, requestId });
    if (!handler) {
      logger.error('no handler registered for job');
      deps.metrics?.jobsTotal.inc({ queue, name: job.name, status: 'unhandled' });
      throw new UnrecoverableError(`No handler for job "${job.name}" on queue "${queue}"`);
    }
    const ctx: JobContext = {
      queue,
      name: job.name,
      jobId: job.id ?? 'unknown',
      attempt,
      maxAttempts,
      isFinalAttempt: attempt >= maxAttempts,
      requestId,
      logger,
      progress: job.progress,
      reportProgress: async (value) => {
        await job.updateProgress(value);
      },
    };
    const stopTimer = deps.metrics?.jobDuration.startTimer({ queue, name: job.name });
    try {
      const result = await handler(job.data, ctx);
      stopTimer?.({ status: 'completed' });
      deps.metrics?.jobsTotal.inc({ queue, name: job.name, status: 'completed' });
      logger.debug('job completed');
      return result;
    } catch (error) {
      const finalFailure = error instanceof UnrecoverableError || ctx.isFinalAttempt;
      const status = finalFailure ? 'failed' : 'retrying';
      stopTimer?.({ status });
      deps.metrics?.jobsTotal.inc({ queue, name: job.name, status });
      const fields = { ...errorFields(error), maxAttempts, final: finalFailure };
      if (finalFailure) logger.error(fields, 'job failed permanently');
      else logger.warn(fields, 'job attempt failed; will retry');
      throw error;
    }
  };
}
