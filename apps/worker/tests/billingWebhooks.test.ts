import type { FastifyInstance } from 'fastify';
import Stripe from 'stripe';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { MemoryWebhookEventStore } from '../src/db/webhookEvents.js';
import { clientIp, createAllowlist } from '../src/http/ipAllowlist.js';
import { buildServer } from '../src/http/server.js';
import { createSilentLogger } from '../src/logger.js';
import { Metrics } from '../src/metrics.js';
import { MemoryEnqueuer } from '../src/queues/enqueuer.js';
import { PRODUCT } from './helpers/bot.js';

const SECRET = 'whsec_test_local';
const ACCOUNT_SECRET = 'whsec_merchant_7';
const YOOKASSA_IP = '185.71.76.5';

let server: FastifyInstance;
let enqueuer: MemoryEnqueuer;
let events: MemoryWebhookEventStore;
const secretFor = vi.fn(async (accountId: number) => (accountId === 7 ? ACCOUNT_SECRET : null));

async function build(trustedProxyHops = 0): Promise<void> {
  enqueuer = new MemoryEnqueuer();
  events = new MemoryWebhookEventStore();
  server = await buildServer({
    logger: createSilentLogger(),
    metrics: new Metrics({ defaultMetrics: false }),
    enqueuer,
    webhookEvents: events,
    product: PRODUCT,
    stripeWebhookSecret: SECRET,
    yookassaWebhook: { allowedIps: ['185.71.76.0/27', '77.75.156.11', '2a02:5180::/32'], trustedProxyHops },
    paymentAccountSecrets: secretFor,
    telegramWebhook: { enabled: false, secret: undefined },
    devBot: null,
    dashboard: null,
    readiness: {},
  });
}

beforeEach(async () => {
  secretFor.mockClear();
  await build();
});

afterEach(async () => {
  await server.close();
});

const notification = (id = 'pay_1') => ({ type: 'notification', event: 'payment.succeeded', object: { id, status: 'succeeded' } });

function postYooKassa(body: object, remoteAddress = YOOKASSA_IP, headers: Record<string, string> = {}) {
  return server.inject({ method: 'POST', url: '/webhooks/yookassa', payload: body, remoteAddress, headers });
}

describe('ip allowlist', () => {
  it('matches IPv4 and IPv6 CIDRs and IPv4-mapped addresses', () => {
    const allowed = createAllowlist(['185.71.76.0/27', '77.75.156.11', '2a02:5180::/32']);
    expect(allowed('185.71.76.31')).toBe(true);
    expect(allowed('185.71.76.32')).toBe(false);
    expect(allowed('::ffff:77.75.156.11')).toBe(true);
    expect(allowed('2a02:5180:0:1::5')).toBe(true);
    expect(allowed('not-an-ip')).toBe(false);
    expect(() => createAllowlist(['300.1.1.1/8'])).toThrow();
  });

  it('only trusts the configured number of proxy hops', () => {
    expect(clientIp('10.0.0.2', '1.2.3.4, 185.71.76.5', 0)).toBe('10.0.0.2');
    expect(clientIp('10.0.0.2', '1.2.3.4, 185.71.76.5', 1)).toBe('185.71.76.5');
    expect(clientIp('10.0.0.2', undefined, 3)).toBe('10.0.0.2');
  });
});

describe('POST /webhooks/yookassa', () => {
  it('accepts allowlisted notifications and dedupes by event and object id', async () => {
    const first = await postYooKassa(notification());
    expect(first.statusCode).toBe(200);
    expect(first.json()).toEqual({ received: true, duplicate: false });
    expect(enqueuer.jobs[0]).toMatchObject({ queue: 'billing', name: 'yookassa.notification', jobId: 'yookassa-payment-succeeded-pay_1' });

    const again = await postYooKassa(notification());
    expect(again.json()).toEqual({ received: true, duplicate: true });
    expect(enqueuer.jobs).toHaveLength(1);

    await events.markProcessed('yookassa', 'payment.succeeded:pay_1');
    expect((await postYooKassa(notification())).json()).toEqual({ received: true, duplicate: true });
  });

  it('rejects other addresses, spoofed X-Forwarded-For and invalid payloads', async () => {
    expect((await postYooKassa(notification(), '203.0.113.9')).statusCode).toBe(403);
    expect((await postYooKassa(notification(), '203.0.113.9', { 'x-forwarded-for': YOOKASSA_IP })).statusCode).toBe(403);
    expect((await postYooKassa({ type: 'notification', event: 'payment.succeeded', object: { id: '../x' } })).statusCode).toBe(400);
    expect((await postYooKassa({ type: 'notification', event: 'refund.succeeded', object: { id: 'r1' } })).statusCode).toBe(400);
    expect(enqueuer.jobs).toHaveLength(0);
  });

  it('uses X-Forwarded-For only behind a trusted proxy', async () => {
    await server.close();
    await build(1);
    expect((await postYooKassa(notification(), '10.0.0.2', { 'x-forwarded-for': YOOKASSA_IP })).statusCode).toBe(200);
    expect((await postYooKassa(notification('pay_2'), '10.0.0.2', { 'x-forwarded-for': '203.0.113.9' })).statusCode).toBe(403);
  });

  it('re-runs a failed job when YooKassa redelivers', async () => {
    await postYooKassa(notification());
    enqueuer.failed.add('yookassa-payment-succeeded-pay_1');
    await postYooKassa(notification());
    expect(enqueuer.retried).toEqual(['yookassa-payment-succeeded-pay_1']);
  });
});

describe('POST /webhooks/payments/stripe/:accountId', () => {
  const event = (id = 'evt_acct_1'): string =>
    JSON.stringify({ id, object: 'event', type: 'checkout.session.completed', data: { object: { payment_link: 'plink_1' } } });
  const post = (accountId: string, payload: string, secret = ACCOUNT_SECRET) =>
    server.inject({
      method: 'POST',
      url: `/webhooks/payments/stripe/${accountId}`,
      payload,
      headers: { 'content-type': 'application/json', 'stripe-signature': Stripe.webhooks.generateTestHeaderString({ payload, secret }) },
    });

  it('verifies with the account signing secret and enqueues once', async () => {
    const payload = event();
    const response = await post('7', payload);
    expect(response.statusCode).toBe(200);
    expect(enqueuer.jobs[0]).toMatchObject({ queue: 'billing', name: 'stripe.account.event', jobId: 'acct-7-evt_acct_1', data: { accountId: 7 } });
    expect((await post('7', payload)).json()).toEqual({ received: true, duplicate: true });
    expect(secretFor).toHaveBeenCalledTimes(1);
  });

  it('rejects the platform secret, unknown accounts and bad ids', async () => {
    expect((await post('7', event('evt_x'), SECRET)).statusCode).toBe(400);
    expect((await post('8', event('evt_y'))).statusCode).toBe(404);
    expect((await post('abc', event('evt_z'))).statusCode).toBe(404);
    expect(enqueuer.jobs).toHaveLength(0);
  });
});

describe('POST /webhooks/stripe redelivery', () => {
  it('retries a failed job instead of silently ignoring the redelivery', async () => {
    const payload = JSON.stringify({ id: 'evt_failed', object: 'event', type: 'invoice.paid', data: { object: {} } });
    const send = () =>
      server.inject({
        method: 'POST',
        url: '/webhooks/stripe',
        payload,
        headers: { 'content-type': 'application/json', 'stripe-signature': Stripe.webhooks.generateTestHeaderString({ payload, secret: SECRET }) },
      });
    await send();
    enqueuer.failed.add('evt_failed');
    await send();
    expect(enqueuer.retried).toEqual(['evt_failed']);
  });
});
