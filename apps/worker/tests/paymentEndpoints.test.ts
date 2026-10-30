import type { FastifyInstance } from 'fastify';
import Stripe from 'stripe';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { MemoryWebhookEventStore } from '../src/db/webhookEvents.js';
import { EndpointCache } from '../src/http/routes/paymentEndpoints.js';
import { buildServer } from '../src/http/server.js';
import { createSilentLogger } from '../src/logger.js';
import { Metrics } from '../src/metrics.js';
import type { PaymentEndpoint } from '../src/payments/endpoints.js';
import { providerEventJobId } from '../src/payments/endpoints.js';
import { MemoryEnqueuer } from '../src/queues/enqueuer.js';
import { PRODUCT } from './helpers/bot.js';

const LAB_KEY = 'Lab0000000000000000000000000000a';
const NORTH_KEY = 'North00000000000000000000000000b';
const LAB_SECRET = 'whsec_fake_lab_secret';
const NORTH_SECRET = 'whsec_fake_northstar_secret';

const endpoints: Record<string, PaymentEndpoint> = {
  [LAB_KEY]: { payment_account_id: 12, workspace_id: 4, webhook_secret: LAB_SECRET, driver: 'fake' },
  [NORTH_KEY]: { payment_account_id: 3, workspace_id: 1, webhook_secret: NORTH_SECRET, driver: 'stripe' },
};

let server: FastifyInstance;
let enqueuer: MemoryEnqueuer;
let events: MemoryWebhookEventStore;
const lookup = vi.fn(async (key: string): Promise<PaymentEndpoint | null> => (endpoints[key] ? { ...endpoints[key] } : null));

beforeEach(async () => {
  lookup.mockClear();
  enqueuer = new MemoryEnqueuer();
  events = new MemoryWebhookEventStore();
  server = await buildServer({
    logger: createSilentLogger(),
    metrics: new Metrics({ defaultMetrics: false }),
    enqueuer,
    webhookEvents: events,
    product: PRODUCT,
    stripeWebhookSecret: 'whsec_platform',
    paymentEndpoints: lookup,
    telegramWebhook: { enabled: false, secret: undefined },
    devBot: null,
    dashboard: null,
    readiness: {},
  });
});

afterEach(async () => {
  await server.close();
});

const checkout = (id: string, extra: Record<string, unknown> = {}): string =>
  JSON.stringify({
    id,
    object: 'event',
    type: 'checkout.session.completed',
    data: { object: { id: 'cs_test_1', payment_link: 'plink_fake_1', payment_intent: 'pi_1', payment_status: 'paid', ...extra } },
  });

function post(key: string, payload: string, secret: string, timestamp?: number) {
  const header = Stripe.webhooks.generateTestHeaderString({ payload, secret, ...(timestamp === undefined ? {} : { timestamp }) });
  return server.inject({
    method: 'POST',
    url: `/webhooks/stripe/accounts/${key}`,
    payload,
    headers: { 'content-type': 'application/json', 'stripe-signature': header },
  });
}

describe('POST /webhooks/stripe/accounts/:endpointKey', () => {
  it('verifies with the secret of the endpoint workspace and enqueues the event once', async () => {
    const payload = checkout('evt_lab_1');
    const first = await post(LAB_KEY, payload, LAB_SECRET);
    expect(first.statusCode).toBe(200);
    expect(first.json()).toEqual({ received: true, duplicate: false });
    expect(enqueuer.jobs).toHaveLength(1);
    expect(enqueuer.jobs[0]).toMatchObject({
      queue: 'payment-events',
      name: 'payment.event',
      jobId: 'pevt-fake-evt_lab_1',
      data: { driver: 'fake', payment_account_id: 12, workspace_id: 4, event: { id: 'evt_lab_1', type: 'checkout.session.completed' } },
    });
    expect(events.rows.get('payment-fake:evt_lab_1')?.payload).toEqual({
      id: 'evt_lab_1',
      type: 'checkout.session.completed',
      payment_account_id: 12,
      workspace_id: 4,
    });

    const second = await post(LAB_KEY, payload, LAB_SECRET);
    expect(second.statusCode).toBe(200);
    expect(second.json()).toEqual({ received: true, duplicate: true });
    expect(enqueuer.jobs).toHaveLength(1);
    expect(lookup).toHaveBeenCalledTimes(1);

    await events.markProcessed('payment-fake', 'evt_lab_1');
    expect((await post(LAB_KEY, payload, LAB_SECRET)).json()).toEqual({ received: true, duplicate: true });
  });

  it('rejects bad and stale signatures, unknown keys and a secret of another workspace', async () => {
    const bad = await server.inject({
      method: 'POST',
      url: `/webhooks/stripe/accounts/${LAB_KEY}`,
      payload: checkout('evt_bad'),
      headers: { 'content-type': 'application/json', 'stripe-signature': 't=1,v1=deadbeef' },
    });
    expect(bad.statusCode).toBe(400);
    expect(bad.json()).toEqual({ error: 'invalid_signature' });
    expect((await post(LAB_KEY, checkout('evt_stale'), LAB_SECRET, Math.floor(Date.now() / 1000) - 600)).statusCode).toBe(400);
    expect((await post('Unknown000000000000000000000000c', checkout('evt_unknown'), LAB_SECRET)).statusCode).toBe(404);
    expect((await post(LAB_KEY, checkout('evt_cross'), NORTH_SECRET)).statusCode).toBe(400);
    expect((await post('short', checkout('evt_short'), LAB_SECRET)).statusCode).toBe(404);
    const unsigned = await server.inject({ method: 'POST', url: `/webhooks/stripe/accounts/${LAB_KEY}`, payload: checkout('evt_unsigned'), headers: { 'content-type': 'application/json' } });
    expect(unsigned.statusCode).toBe(400);
    expect(enqueuer.jobs).toHaveLength(0);
    expect(events.rows.size).toBe(0);
  });

  it('routes each workspace to its own account and rejects signed payloads that are not events', async () => {
    expect((await post(NORTH_KEY, checkout('evt_north_1'), NORTH_SECRET)).statusCode).toBe(200);
    expect(enqueuer.jobs[0]).toMatchObject({ jobId: 'pevt-stripe-evt_north_1', data: { driver: 'stripe', payment_account_id: 3, workspace_id: 1 } });
    const notAnEvent = JSON.stringify({ id: 'evt_x', type: 'checkout.session.completed', object: 'checkout.session' });
    expect((await post(NORTH_KEY, notAnEvent, NORTH_SECRET)).statusCode).toBe(400);
  });

  it('re-runs a failed job when the provider redelivers', async () => {
    const payload = checkout('evt_retry');
    await post(LAB_KEY, payload, LAB_SECRET);
    enqueuer.failed.add('pevt-fake-evt_retry');
    await post(LAB_KEY, payload, LAB_SECRET);
    expect(enqueuer.retried).toEqual(['pevt-fake-evt_retry']);
  });

  it('answers 503 when the endpoint lookup fails so the provider retries', async () => {
    lookup.mockRejectedValueOnce(new Error('web down'));
    expect((await post(LAB_KEY, checkout('evt_down'), LAB_SECRET)).statusCode).toBe(503);
    expect((await post(LAB_KEY, checkout('evt_down'), LAB_SECRET)).statusCode).toBe(200);
  });
});

describe('EndpointCache', () => {
  it('caches hits for five minutes, misses briefly and throttles refreshes', async () => {
    let now = 1_000_000;
    const source = vi.fn(async (key: string) => (key === 'known' ? endpoints[LAB_KEY]! : null));
    const cache = new EndpointCache(source, 300_000, 30_000, () => now);
    await cache.resolve('known');
    await cache.resolve('missing');
    now += 29_000;
    await cache.resolve('known');
    await cache.resolve('missing');
    expect(source).toHaveBeenCalledTimes(2);
    expect(await cache.refresh('known')).toBeUndefined();
    now += 2_000;
    await cache.resolve('missing');
    expect(source).toHaveBeenCalledTimes(3);
    expect(await cache.refresh('known')).toEqual(endpoints[LAB_KEY]);
    now += 299_000;
    await cache.resolve('known');
    expect(source).toHaveBeenCalledTimes(4);
  });
});

describe('providerEventJobId', () => {
  it('builds colon-free ids and hashes unsafe event ids', () => {
    expect(providerEventJobId('stripe', 'evt_1Nx')).toBe('pevt-stripe-evt_1Nx');
    const hashed = providerEventJobId('fake', 'evt:with:colons');
    expect(hashed).toMatch(/^pevt-fake-[0-9a-f]{40}$/);
    expect(hashed).toBe(providerEventJobId('fake', 'evt:with:colons'));
  });
});
