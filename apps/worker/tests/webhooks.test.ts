import type { FastifyInstance } from 'fastify';
import Stripe from 'stripe';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { MemoryWebhookEventStore } from '../src/db/webhookEvents.js';
import { buildServer } from '../src/http/server.js';
import { createSilentLogger } from '../src/logger.js';
import { Metrics } from '../src/metrics.js';
import { MemoryEnqueuer } from '../src/queues/enqueuer.js';
import { PRODUCT } from './helpers/bot.js';

const SECRET = 'whsec_test_local';
const TG_SECRET = 'tg-webhook-secret-123';

let server: FastifyInstance;
let enqueuer: MemoryEnqueuer;
let events: MemoryWebhookEventStore;
let metrics: Metrics;

beforeEach(async () => {
  enqueuer = new MemoryEnqueuer();
  events = new MemoryWebhookEventStore();
  metrics = new Metrics({ defaultMetrics: false });
  server = await buildServer({
    logger: createSilentLogger(),
    metrics,
    enqueuer,
    webhookEvents: events,
    product: PRODUCT,
    stripeWebhookSecret: SECRET,
    telegramWebhook: { enabled: true, secret: TG_SECRET },
    devBot: null,
    dashboard: null,
    readiness: { postgres: async () => undefined, redis: async () => Promise.reject(new Error('down')) },
  });
});

afterEach(async () => {
  await server.close();
});

function stripeEvent(id = 'evt_test_1', type = 'checkout.session.completed'): string {
  return JSON.stringify({ id, object: 'event', type, data: { object: { id: 'cs_test_1', metadata: { workspace_id: '3' } } } });
}

async function postStripe(payload: string, signature?: string) {
  return server.inject({
    method: 'POST',
    url: '/webhooks/stripe',
    payload,
    headers: { 'content-type': 'application/json; charset=utf-8', ...(signature ? { 'stripe-signature': signature } : {}) },
  });
}

describe('POST /webhooks/stripe', () => {
  it('verifies the signature over the raw body and enqueues with jobId = event.id', async () => {
    const payload = stripeEvent();
    const response = await postStripe(payload, Stripe.webhooks.generateTestHeaderString({ payload, secret: SECRET }));
    expect(response.statusCode).toBe(200);
    expect(response.json()).toEqual({ received: true, duplicate: false });
    expect(enqueuer.jobs).toHaveLength(1);
    expect(enqueuer.jobs[0]).toMatchObject({ queue: 'stripe-events', name: 'stripe.event', jobId: 'evt_test_1', data: { id: 'evt_test_1' } });
    expect(events.rows.get('stripe:evt_test_1')).toMatchObject({ type: 'checkout.session.completed', processed: false });
  });

  it('keeps whitespace-sensitive raw bodies intact', async () => {
    const payload = `{\n  "id": "evt_pretty", "object": "event",   "type": "invoice.paid", "data": {"object": {}}\n}`;
    const response = await postStripe(payload, Stripe.webhooks.generateTestHeaderString({ payload, secret: SECRET }));
    expect(response.statusCode).toBe(200);
  });

  it('rejects invalid, stale and missing signatures', async () => {
    const payload = stripeEvent();
    const wrongSecret = await postStripe(payload, Stripe.webhooks.generateTestHeaderString({ payload, secret: 'whsec_other' }));
    expect(wrongSecret.statusCode).toBe(400);
    expect(wrongSecret.json()).toEqual({ error: 'invalid_signature' });

    const stale = await postStripe(
      payload,
      Stripe.webhooks.generateTestHeaderString({ payload, secret: SECRET, timestamp: Math.floor(Date.now() / 1000) - 3600 }),
    );
    expect(stale.statusCode).toBe(400);

    const tampered = await postStripe(stripeEvent('evt_other'), Stripe.webhooks.generateTestHeaderString({ payload, secret: SECRET }));
    expect(tampered.statusCode).toBe(400);

    const missing = await postStripe(payload);
    expect(missing.statusCode).toBe(400);
    expect(missing.json()).toEqual({ error: 'missing_signature' });
    expect(enqueuer.jobs).toHaveLength(0);
  });

  it('dedupes redeliveries: re-enqueue is idempotent, processed events are skipped', async () => {
    const payload = stripeEvent('evt_dupe');
    const header = () => Stripe.webhooks.generateTestHeaderString({ payload, secret: SECRET });
    await postStripe(payload, header());
    const second = await postStripe(payload, header());
    expect(second.json()).toEqual({ received: true, duplicate: true });
    expect(enqueuer.jobs).toHaveLength(1);

    await events.markProcessed('stripe', 'evt_dupe');
    enqueuer.jobs.length = 0;
    const third = await postStripe(payload, header());
    expect(third.json()).toEqual({ received: true, duplicate: true });
    expect(enqueuer.jobs).toHaveLength(0);
  });

  it('returns 500 when the queue is unavailable so Stripe retries', async () => {
    enqueuer.failNext = new Error('redis down');
    const payload = stripeEvent('evt_retry');
    const response = await postStripe(payload, Stripe.webhooks.generateTestHeaderString({ payload, secret: SECRET }));
    expect(response.statusCode).toBe(500);
    const retry = await postStripe(payload, Stripe.webhooks.generateTestHeaderString({ payload, secret: SECRET }));
    expect(retry.statusCode).toBe(200);
    expect(enqueuer.jobs.map((job) => job.jobId)).toEqual(['evt_retry']);
  });
});

describe('POST /webhooks/telegram', () => {
  const update = { update_id: 900001, message: { message_id: 1, date: 1, chat: { id: 1, type: 'private' }, text: 'hi' } };

  it('requires the secret token header', async () => {
    const missing = await server.inject({ method: 'POST', url: '/webhooks/telegram', payload: update });
    expect(missing.statusCode).toBe(401);
    const wrong = await server.inject({
      method: 'POST',
      url: '/webhooks/telegram',
      payload: update,
      headers: { 'x-telegram-bot-api-secret-token': 'nope' },
    });
    expect(wrong.statusCode).toBe(401);
    expect(enqueuer.jobs).toHaveLength(0);
  });

  it('enqueues updates to telegram-updates with a job id derived from update_id', async () => {
    const headers = { 'x-telegram-bot-api-secret-token': TG_SECRET };
    const first = await server.inject({ method: 'POST', url: '/webhooks/telegram', payload: update, headers });
    const again = await server.inject({ method: 'POST', url: '/webhooks/telegram', payload: update, headers });
    expect(first.statusCode).toBe(200);
    expect(again.statusCode).toBe(200);
    expect(enqueuer.jobs).toEqual([{ queue: 'telegram-updates', name: 'telegram.update', jobId: 'tg-update-900001', data: update }]);
  });

  it('rejects bodies without update_id', async () => {
    const response = await server.inject({
      method: 'POST',
      url: '/webhooks/telegram',
      payload: { hello: 'world' },
      headers: { 'x-telegram-bot-api-secret-token': TG_SECRET },
    });
    expect(response.statusCode).toBe(400);
  });
});

describe('health, readiness and metrics', () => {
  it('serves liveness, readiness with dependency status and Prometheus metrics', async () => {
    const health = await server.inject({ method: 'GET', url: '/healthz' });
    expect(health.json()).toMatchObject({ status: 'ok', service: 'worker', product: 'acme' });
    expect(health.headers['x-request-id']).toBeTruthy();

    const ready = await server.inject({ method: 'GET', url: '/readyz' });
    expect(ready.statusCode).toBe(503);
    expect(ready.json()).toEqual({ status: 'degraded', checks: { postgres: 'ok', redis: 'down' } });

    metrics.setQueueDepthSource(async () => [{ queue: 'emails', counts: { waiting: 3, failed: 1 } }]);
    const scrape = await server.inject({ method: 'GET', url: '/metrics' });
    expect(scrape.headers['content-type']).toContain('text/plain');
    expect(scrape.body).toContain('worker_queue_depth{queue="emails",state="waiting"} 3');
    expect(scrape.body).toContain('worker_jobs_total');
  });

  it('does not expose dev routes or the dashboard when disabled', async () => {
    expect((await server.inject({ method: 'POST', url: '/dev/telegram/update', payload: {} })).statusCode).toBe(404);
    expect((await server.inject({ method: 'GET', url: '/admin/queues' })).statusCode).toBe(404);
  });

  it('propagates an incoming request id', async () => {
    const response = await server.inject({ method: 'GET', url: '/healthz', headers: { 'x-request-id': 'req-123' } });
    expect(response.headers['x-request-id']).toBe('req-123');
  });
});
