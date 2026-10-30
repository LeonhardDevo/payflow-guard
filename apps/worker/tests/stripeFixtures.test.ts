import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { FastifyInstance } from 'fastify';
import Stripe from 'stripe';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { MemoryWebhookEventStore } from '../src/db/webhookEvents.js';
import { buildServer } from '../src/http/server.js';
import { createSilentLogger } from '../src/logger.js';
import { Metrics } from '../src/metrics.js';
import { MemoryEnqueuer } from '../src/queues/enqueuer.js';
import { PRODUCT } from './helpers/bot.js';

const SECRET = 'whsec_fixture_secret';
const FIXTURES = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', 'contracts', 'stripe', 'events');
const REQUIRED_EVENTS = [
  'checkout.session.completed',
  'customer.subscription.created',
  'customer.subscription.updated',
  'customer.subscription.deleted',
  'customer.subscription.trial_will_end',
  'invoice.paid',
  'invoice.payment_failed',
];

const fixtures = readdirSync(FIXTURES)
  .filter((file) => file.endsWith('.json'))
  .map((file) => ({ type: file.replace(/\.json$/, ''), payload: readFileSync(join(FIXTURES, file), 'utf8') }));

let server: FastifyInstance;
let enqueuer: MemoryEnqueuer;
let events: MemoryWebhookEventStore;

beforeEach(async () => {
  enqueuer = new MemoryEnqueuer();
  events = new MemoryWebhookEventStore();
  server = await buildServer({
    logger: createSilentLogger(),
    metrics: new Metrics({ defaultMetrics: false }),
    enqueuer,
    webhookEvents: events,
    product: PRODUCT,
    stripeWebhookSecret: SECRET,
    telegramWebhook: { enabled: false, secret: 'unused-secret-value' },
    devBot: null,
    dashboard: null,
    readiness: { postgres: async () => undefined, redis: async () => undefined },
  });
});

afterEach(async () => {
  await server.close();
});

function post(payload: string, signature: string) {
  return server.inject({
    method: 'POST',
    url: '/webhooks/stripe',
    payload,
    headers: { 'content-type': 'application/json; charset=utf-8', 'stripe-signature': signature },
  });
}

describe('Stripe event fixtures (contracts/stripe/events)', () => {
  it('cover every event of the SaaS billing contract', () => {
    expect(fixtures.map((fixture) => fixture.type).sort()).toEqual([...REQUIRED_EVENTS].sort());
  });

  it.each(fixtures)('accepts a signed $type and enqueues it unchanged', async ({ type, payload }) => {
    const event = JSON.parse(payload) as { id: string; type: string };
    expect(event.type).toBe(type);

    const response = await post(payload, Stripe.webhooks.generateTestHeaderString({ payload, secret: SECRET }));

    expect(response.statusCode).toBe(200);
    expect(response.json()).toEqual({ received: true, duplicate: false });
    expect(enqueuer.jobs).toHaveLength(1);
    expect(enqueuer.jobs[0]).toMatchObject({ queue: 'stripe-events', name: 'stripe.event', jobId: event.id });
    expect(enqueuer.jobs[0]?.data).toEqual(JSON.parse(payload));
    expect(events.rows.get(`stripe:${event.id}`)).toMatchObject({ type, processed: false });
  });

  it.each(fixtures)('rejects a tampered $type', async ({ payload }) => {
    const signature = Stripe.webhooks.generateTestHeaderString({ payload, secret: SECRET });
    const tampered = payload.replace('"livemode": false', '"livemode": true');

    const response = await post(tampered, signature);

    expect(response.statusCode).toBe(400);
    expect(response.json()).toEqual({ error: 'invalid_signature' });
    expect(enqueuer.jobs).toHaveLength(0);
  });
});
