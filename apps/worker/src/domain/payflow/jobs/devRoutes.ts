import type { FastifyPluginAsync } from 'fastify';
import { z } from 'zod';
import { UpstreamHttpError, UpstreamNetworkError } from '../../../clients/errors.js';
import { errorFields } from '../../../logger.js';
import { PF_JOBS } from './definitions.js';
import { MAX_DRAIN_TIMEOUT_MS, UnknownQueueError, type DevJobRunner } from './runner.js';

export const DEV_RUNNABLE_JOBS = [
  PF_JOBS.remindersPlanAll,
  PF_JOBS.riskRecomputeAll,
  PF_JOBS.digestsRun,
  PF_JOBS.paymentsSyncAll,
  PF_JOBS.guardHourly,
  PF_JOBS.maintenancePurge,
] as const;

const runBodySchema = z.object({
  job: z.enum(DEV_RUNNABLE_JOBS),
  now: z
    .string()
    .refine((value) => Number.isFinite(Date.parse(value)), 'must be an RFC 3339 instant')
    .optional(),
});

const drainBodySchema = z.object({
  queues: z.array(z.string().regex(/^[a-z][a-z0-9-]{1,40}$/)).min(1).max(20),
  promote_delayed: z.boolean().default(false),
  timeout_ms: z.number().int().positive().max(MAX_DRAIN_TIMEOUT_MS).default(10_000),
});

export function devJobRoutes(runner: DevJobRunner): FastifyPluginAsync {
  return async (app) => {
    app.post('/dev/jobs/run', async (request, reply) => {
      const parsed = runBodySchema.safeParse(request.body ?? {});
      if (!parsed.success) return reply.code(422).send({ error: 'invalid_request', details: z.prettifyError(parsed.error) });
      const now = parsed.data.now === undefined ? null : new Date(Date.parse(parsed.data.now)).toISOString();
      try {
        return await runner.run(parsed.data.job, now);
      } catch (error) {
        request.log.warn({ job: parsed.data.job, ...errorFields(error) }, 'dev job run failed');
        if (error instanceof UpstreamHttpError || error instanceof UpstreamNetworkError) {
          return reply.code(502).send({ error: 'upstream_failed', message: error.message });
        }
        throw error;
      }
    });

    app.post('/dev/jobs/drain', async (request, reply) => {
      const parsed = drainBodySchema.safeParse(request.body ?? {});
      if (!parsed.success) return reply.code(422).send({ error: 'invalid_request', details: z.prettifyError(parsed.error) });
      try {
        const result = await runner.drain({
          queues: parsed.data.queues,
          promoteDelayed: parsed.data.promote_delayed,
          timeoutMs: parsed.data.timeout_ms,
        });
        return result;
      } catch (error) {
        if (error instanceof UnknownQueueError) return reply.code(422).send({ error: 'unknown_queue', queue: error.queue });
        throw error;
      }
    });
  };
}
