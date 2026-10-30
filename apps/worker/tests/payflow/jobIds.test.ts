import { Redis } from 'ioredis';
import Fastify, { type FastifyInstance } from 'fastify';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { dispatchJobOptions, jobIds, PF_JOBS, PF_QUEUES, PF_SCHEDULERS, SCHEDULER_JOB_OPTIONS } from '../../src/domain/payflow/jobs/definitions.js';
import { devJobRoutes } from '../../src/domain/payflow/jobs/devRoutes.js';
import { PayflowJobs } from '../../src/domain/payflow/jobs/handlers.js';
import { DevJobRunner, JobActivity, registerPayflowSchedulers } from '../../src/domain/payflow/jobs/runner.js';
import { createSilentLogger } from '../../src/logger.js';
import { QueueRegistry } from '../../src/queues/enqueuer.js';
import { providerEventJobId } from '../../src/payments/endpoints.js';
import { JOB_NAMES, QUEUES, type QueueName } from '../../src/queues/names.js';
import { redisOptionsFromUrl } from '../../src/queues/redis.js';
import { closeWorkers, startWorkers } from '../../src/queues/workers.js';
import { announceSkip, redisAvailable, testRedisUrl, uniqueSuffix } from '../helpers/infra.js';
import { fakePayflowApi } from './fakes.js';

const redisUrl = await redisAvailable();
const skip = redisUrl ? '' : announceSkip('Redis (PayFlow job ids)', testRedisUrl());
const prefix = `pf-test-${uniqueSuffix()}`;

describe.skipIf(!redisUrl)(`PayFlow job ids on a real BullMQ queue${skip}`, () => {
  let registry: QueueRegistry;
  let redis: Redis;

  beforeAll(() => {
    registry = new QueueRegistry(redisOptionsFromUrl(redisUrl!), prefix, [...PF_QUEUES, QUEUES.paymentEvents]);
    redis = new Redis(redisOptionsFromUrl(redisUrl!));
  });

  afterAll(async () => {
    for (const queue of registry.queues.values()) await queue.obliterate({ force: true }).catch(() => undefined);
    await registry.close();
    const keys = await redis.keys(`${prefix}*`);
    if (keys.length > 0) await redis.del(...keys);
    await redis.quit();
  });

  it('accepts every id the domain builds and dedupes the second add', async () => {
    const ids: Array<[QueueName, string, string]> = [
      [QUEUES.pfReminders, PF_JOBS.remindersPlan, jobIds.plan(3, 1761210000)],
      [QUEUES.pfReminders, PF_JOBS.remindersDispatch, jobIds.dispatch(41, '2026-10-24T06:17:00Z')],
      [QUEUES.pfReminders, PF_JOBS.remindersDispatch, jobIds.dispatchRetry(41, '2026-10-24T06:17:00Z', 5)],
      [QUEUES.pfAnalytics, PF_JOBS.riskRecompute, jobIds.risk(3, '2026-10-23')],
      [QUEUES.pfPayments, PF_JOBS.paymentsSync, jobIds.sync(11, 1761210000)],
      [QUEUES.pfImports, 'payflow.import.requested', '7d7f1a52-0d1f-4d9e-9ad4-4f3e6c2c1b10'],
      [QUEUES.paymentEvents, JOB_NAMES.paymentEvent, providerEventJobId('stripe', 'evt_1NxQ2bLkdIwHu7ix')],
      [QUEUES.paymentEvents, JOB_NAMES.paymentEvent, providerEventJobId('fake', 'evt:fake:1')],
    ];
    for (const [queue, name, id] of ids) {
      expect(id).not.toContain(':');
      const options = name === PF_JOBS.remindersDispatch ? { job: dispatchJobOptions(60_000) } : {};
      await expect(registry.add(queue, name, { id }, id, options)).resolves.toEqual({ jobId: id, created: true });
      await expect(registry.add(queue, name, { id }, id, options)).resolves.toEqual({ jobId: id, created: false });
    }
    const delayed = await registry.get(QUEUES.pfReminders).getJob(jobIds.dispatch(41, '2026-10-24T06:17:00Z'));
    expect(delayed?.opts).toMatchObject({ attempts: 8, delay: 60_000, removeOnComplete: true, removeOnFail: true });
  });

  it('registers the job schedulers without colons in their ids', async () => {
    const queue = registry.get(QUEUES.pfScheduler);
    await registerPayflowSchedulers(queue, createSilentLogger());
    await registerPayflowSchedulers(queue, createSilentLogger());
    const schedulers = await queue.getJobSchedulers();
    expect(schedulers.map((scheduler) => scheduler.key).sort()).toEqual(PF_SCHEDULERS.map((scheduler) => scheduler.id).sort());
    const delayed = await queue.getDelayed();
    expect(delayed.length).toBe(PF_SCHEDULERS.length);
    expect(delayed[0]?.opts).toMatchObject({ attempts: SCHEDULER_JOB_OPTIONS.attempts });
    await registerPayflowSchedulers(queue, createSilentLogger(), false);
    expect(await queue.getJobSchedulers()).toEqual([]);
  });

  describe('dev job runner', () => {
    let app: FastifyInstance;
    let api: ReturnType<typeof fakePayflowApi>;
    const activity = new JobActivity();
    let workers: Awaited<ReturnType<typeof startWorkers>> = [];

    beforeAll(async () => {
      for (const queue of registry.queues.values()) await queue.obliterate({ force: true }).catch(() => undefined);
      api = fakePayflowApi();
      api.planReminders.mockImplementation(async (workspaceId) => ({
        reminders: [{ id: workspaceId * 10, scheduled_for: '2026-10-24T06:17:00Z' }],
        created: 1,
        superseded: 0,
      }));
      api.dispatchReminder.mockResolvedValue({ status: 'queued' });
      const jobs = new PayflowJobs({ api, enqueuer: registry });
      workers = startWorkers(jobs.handlers(), {
        connection: redisOptionsFromUrl(redisUrl!),
        prefix,
        logger: createSilentLogger(),
        onSettled: (queue, outcome) => activity.record(queue, outcome),
      });
      const runner = new DevJobRunner(jobs, (name) => registry.queues.get(name as QueueName) ?? null, activity, createSilentLogger());
      app = Fastify();
      await app.register(devJobRoutes(runner));
      await app.ready();
    });

    afterAll(async () => {
      await app.close();
      await closeWorkers(workers);
    });

    it('runs a scheduler job now and drains the fan-out including delayed dispatches', async () => {
      const now = '2026-10-23T12:00:00Z';
      const started = await app.inject({ method: 'POST', url: '/dev/jobs/run', payload: { job: 'reminders:plan-all', now } });
      expect(started.statusCode).toBe(200);
      expect(started.json()).toEqual({ enqueued: 2, workspaces: 2 });
      const drained = await app.inject({
        method: 'POST',
        url: '/dev/jobs/drain',
        payload: { queues: ['pf-scheduler', 'pf-reminders'], promote_delayed: true, timeout_ms: 15_000 },
      });
      expect(drained.statusCode).toBe(200);
      expect(drained.json()).toEqual({ processed: 4, failed: 0, idle: true });
      expect(api.planReminders).toHaveBeenCalledWith(1, '2026-10-23T12:00:00.000Z', expect.any(String));
      expect(api.dispatchReminder).toHaveBeenCalledWith(10, '2026-10-24T06:17:00Z', '2026-10-24T06:17:00.000Z', expect.any(String));
    });

    it('never promotes job-scheduler iterations while draining', async () => {
      const queue = registry.get(QUEUES.pfScheduler);
      await registerPayflowSchedulers(queue, createSilentLogger());
      const calls = api.activeWorkspaces.mock.calls.length;
      const drained = await app.inject({ method: 'POST', url: '/dev/jobs/drain', payload: { queues: ['pf-scheduler'], promote_delayed: true, timeout_ms: 5000 } });
      expect(drained.json()).toEqual({ processed: 0, failed: 0, idle: true });
      expect(api.activeWorkspaces.mock.calls.length).toBe(calls);
      expect((await queue.getDelayed()).length).toBe(PF_SCHEDULERS.length);
      await registerPayflowSchedulers(queue, createSilentLogger(), false);
    });

    it('validates the request bodies', async () => {
      expect((await app.inject({ method: 'POST', url: '/dev/jobs/run', payload: { job: 'emails:all' } })).statusCode).toBe(422);
      expect((await app.inject({ method: 'POST', url: '/dev/jobs/run', payload: { job: 'digests:run', now: 'soon' } })).statusCode).toBe(422);
      expect((await app.inject({ method: 'POST', url: '/dev/jobs/drain', payload: { queues: ['nope'] } })).json()).toEqual({ error: 'unknown_queue', queue: 'nope' });
      expect((await app.inject({ method: 'POST', url: '/dev/jobs/drain', payload: { queues: ['pf-reminders'], timeout_ms: 60_000 } })).statusCode).toBe(422);
    });

    it('reports upstream failures of a run as 502', async () => {
      api.runDigests.mockRejectedValueOnce(new (await import('../../src/clients/errors.js')).UpstreamHttpError('web', 'POST', '/x', 503, null));
      const response = await app.inject({ method: 'POST', url: '/dev/jobs/run', payload: { job: 'digests:run' } });
      expect(response.statusCode).toBe(502);
    });
  });
});
