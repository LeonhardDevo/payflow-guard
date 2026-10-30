import { setTimeout as sleep } from 'node:timers/promises';
import { QueueEvents, UnrecoverableError, type Job, type Worker } from 'bullmq';
import type { FastifyInstance } from 'fastify';
import { Redis } from 'ioredis';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { RedisActionStore, RedisCache, RedisRateLimiter } from '../src/bot/stores.js';
import { MemoryWebhookEventStore } from '../src/db/webhookEvents.js';
import { buildServer } from '../src/http/server.js';
import { createSilentLogger } from '../src/logger.js';
import { Metrics } from '../src/metrics.js';
import { telegramUpdateJobId } from '../src/queues/defaults.js';
import { QueueRegistry } from '../src/queues/enqueuer.js';
import { ALL_QUEUES } from '../src/queues/names.js';
import { redisOptionsFromUrl } from '../src/queues/redis.js';
import { closeWorkers, startWorkers } from '../src/queues/workers.js';
import { PRODUCT } from './helpers/bot.js';
import { redisAvailable, announceSkip, testRedisUrl, uniqueSuffix } from './helpers/infra.js';

const redisUrl = await redisAvailable();
const skipSuffix = redisUrl ? '' : announceSkip('Redis (BullMQ / store tests)', testRedisUrl());

const prefix = `test-${uniqueSuffix()}`;

describe.skipIf(!redisUrl)(`BullMQ + Redis (real Redis)${skipSuffix}`, () => {
  let registry: QueueRegistry;
  let redis: Redis;

  beforeAll(() => {
    registry = new QueueRegistry(redisOptionsFromUrl(redisUrl!), prefix);
    redis = new Redis(redisOptionsFromUrl(redisUrl!));
  });

  afterAll(async () => {
    for (const queue of registry.queues.values()) await queue.obliterate({ force: true }).catch(() => undefined);
    await registry.close();
    const keys = await redis.keys(`${prefix}*`);
    if (keys.length > 0) await redis.del(...keys);
    await redis.quit();
  });

  it('dedupes jobs by id (idempotent producers)', async () => {
    const first = await registry.add('emails', 'email.send', { a: 1 }, 'outbox-id-1');
    const second = await registry.add('emails', 'email.send', { a: 2 }, 'outbox-id-1');
    expect(first).toEqual({ jobId: 'outbox-id-1', created: true });
    expect(second).toEqual({ jobId: 'outbox-id-1', created: false });
    const job = await registry.get('emails').getJob('outbox-id-1');
    expect(job?.data).toEqual({ a: 1 });
    expect(job?.opts).toMatchObject({ attempts: 5, backoff: { type: 'exponential', delay: 2000 } });
  });

  it('accepts derived Telegram job ids', async () => {
    const result = await registry.add('telegram-updates', 'telegram.update', { update_id: 12 }, telegramUpdateJobId(12));
    expect(result.created).toBe(true);
  });

  it('processes jobs through the instrumented processor', async () => {
    const metrics = new Metrics({ defaultMetrics: false });
    const queue = registry.get('documents');
    const events = new QueueEvents('documents', { connection: redisOptionsFromUrl(redisUrl!), prefix });
    await events.waitUntilReady();
    const workers: Worker[] = startWorkers(
      {
        documents: {
          'workspace.reembed': async (data) => ({ echoed: data }),
          'document.deleted': async () => {
            throw new UnrecoverableError('bad payload');
          },
        },
      },
      { connection: redisOptionsFromUrl(redisUrl!), prefix, logger: createSilentLogger(), metrics },
    );
    try {
      const ok = await queue.add('workspace.reembed', { workspace_id: 3 }, { jobId: 'ok-1' });
      expect(await ok.waitUntilFinished(events, 10_000)).toEqual({ echoed: { workspace_id: 3 } });

      const bad = await queue.add('document.deleted', { document_id: 1 }, { jobId: 'bad-1' });
      await expect(bad.waitUntilFinished(events, 10_000)).rejects.toThrow('bad payload');
      const failed = (await queue.getJob('bad-1')) as Job;
      expect(failed.attemptsMade).toBe(1);

      const rendered = await metrics.render();
      expect(rendered.body).toContain('worker_jobs_total{queue="documents",name="workspace.reembed",status="completed"} 1');
      expect(rendered.body).toContain('worker_jobs_total{queue="documents",name="document.deleted",status="failed"} 1');
    } finally {
      await closeWorkers(workers);
      await events.close();
    }
  });

  it('stores actions with TTL, rate-limits per window and caches values', async () => {
    const keyPrefix = `${prefix}:kv:`;
    const actions = new RedisActionStore(redis, keyPrefix);
    const id = await actions.put(
      { type: 'ask', payload: { question: 'q' }, telegram_user_id: 1, chat_id: 1, workspace_id: 1, user_id: 1, locale: 'en', created_at: 1 },
      30,
    );
    expect(id).toMatch(/^[A-Za-z0-9]{12}$/);
    expect(await actions.get(id)).toMatchObject({ type: 'ask', payload: { question: 'q' } });
    expect(await redis.ttl(`${keyPrefix}action:${id}`)).toBeGreaterThan(0);
    expect(await actions.get('missing')).toBeNull();

    const limiter = new RedisRateLimiter(redis, keyPrefix);
    const hits = [await limiter.hit('chat:1', 60), await limiter.hit('chat:1', 60), await limiter.hit('chat:2', 60)];
    expect(hits).toEqual([1, 2, 1]);

    const cache = new RedisCache(redis, keyPrefix);
    await cache.set('k', 'v', 30);
    expect(await cache.get('k')).toBe('v');
    await cache.del('k');
    expect(await cache.get('k')).toBeNull();
  });

  describe('queue dashboard', () => {
    let server: FastifyInstance;
    const auth = `Basic ${Buffer.from('admin:s3cret').toString('base64')}`;

    beforeAll(async () => {
      server = await buildServer({
        logger: createSilentLogger(),
        metrics: new Metrics({ defaultMetrics: false }),
        enqueuer: registry,
        webhookEvents: new MemoryWebhookEventStore(),
        product: PRODUCT,
        stripeWebhookSecret: 'whsec_test_local',
        telegramWebhook: { enabled: false, secret: undefined },
        devBot: null,
        dashboard: { queues: [...registry.queues.values()], user: 'admin', password: 's3cret' },
        readiness: {},
      });
    });

    afterAll(async () => {
      await server.close();
    });

    it('requires basic auth', async () => {
      const anonymous = await server.inject({ method: 'GET', url: '/admin/queues' });
      expect(anonymous.statusCode).toBe(401);
      expect(anonymous.headers['www-authenticate']).toContain('Basic');
      const wrong = await server.inject({ method: 'GET', url: '/admin/queues', headers: { authorization: `Basic ${Buffer.from('admin:x').toString('base64')}` } });
      expect(wrong.statusCode).toBe(401);
    });

    it('serves the board without any external asset and with a strict CSP', async () => {
      const page = await server.inject({ method: 'GET', url: '/admin/queues', headers: { authorization: auth } });
      expect(page.statusCode).toBe(200);
      expect(page.body).toContain('<div id="root">');
      expect(page.body).not.toMatch(/https?:\/\/(?!localhost)/);
      expect(page.headers['content-security-policy']).toContain("default-src 'self'");

      const api = await server.inject({ method: 'GET', url: '/admin/queues/api/queues', headers: { authorization: auth } });
      expect(api.statusCode).toBe(200);
      const names = (api.json<{ queues: Array<{ name: string }> }>()).queues.map((queue) => queue.name).sort();
      expect(names).toEqual([...ALL_QUEUES].sort());
      await sleep(10);
    });
  });
});
