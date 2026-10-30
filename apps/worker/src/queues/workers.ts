import { UnrecoverableError, Worker, type ConnectionOptions, type Job } from 'bullmq';
import type { HandlerMap } from '../jobs/types.js';
import { errorFields, type Logger } from '../logger.js';
import type { Metrics } from '../metrics.js';
import { customBackoff } from './backoff.js';
import { QUEUE_SETTINGS, type QueueName } from './names.js';
import { createProcessor } from './processor.js';

export type JobOutcome = 'completed' | 'failed' | 'retrying';

export function isFinalFailure(job: Pick<Job, 'attemptsMade' | 'opts'> | undefined, error: unknown): boolean {
  if (!job || error instanceof UnrecoverableError) return true;
  return job.attemptsMade >= (job.opts.attempts ?? 1);
}

export function startWorkers(
  handlers: Partial<Record<QueueName, HandlerMap>>,
  options: { connection: ConnectionOptions; prefix: string; logger: Logger; metrics?: Metrics; onSettled?: (queue: QueueName, status: JobOutcome) => void },
): Worker[] {
  const workers: Worker[] = [];
  for (const [queue, map] of Object.entries(handlers) as Array<[QueueName, HandlerMap | undefined]>) {
    if (!map || Object.keys(map).length === 0) continue;
    const settings = QUEUE_SETTINGS[queue];
    const worker = new Worker(queue, createProcessor(queue, map, options), {
      connection: options.connection,
      prefix: options.prefix,
      concurrency: settings.concurrency,
      settings: { backoffStrategy: customBackoff },
      ...(settings.limiter ? { limiter: settings.limiter } : {}),
    });
    worker.on('error', (error) => options.logger.error({ queue, ...errorFields(error) }, 'queue worker error'));
    if (options.onSettled) {
      const settled = options.onSettled;
      worker.on('completed', () => settled(queue, 'completed'));
      worker.on('failed', (job, error) => settled(queue, isFinalFailure(job, error) ? 'failed' : 'retrying'));
    }
    workers.push(worker);
  }
  options.logger.info({ queues: workers.map((worker) => worker.name) }, 'queue workers started');
  return workers;
}

export async function closeWorkers(workers: Worker[]): Promise<void> {
  await Promise.allSettled(workers.map((worker) => worker.close()));
}
