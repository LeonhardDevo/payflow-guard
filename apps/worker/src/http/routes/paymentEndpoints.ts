import type { FastifyPluginAsync } from 'fastify';
import Stripe from 'stripe';
import type { WebhookEventStore } from '../../db/webhookEvents.js';
import { errorFields } from '../../logger.js';
import type { Metrics } from '../../metrics.js';
import {
  ENDPOINT_KEY_RE,
  paymentEventProvider,
  providerEventJobId,
  providerEventSchema,
  SIGNATURE_TOLERANCE_S,
  type PaymentEndpoint,
  type PaymentEventJob,
} from '../../payments/endpoints.js';
import type { JobEnqueuer } from '../../queues/enqueuer.js';
import { JOB_NAMES, QUEUES } from '../../queues/names.js';

export type PaymentEndpointLookup = (endpointKey: string) => Promise<PaymentEndpoint | null>;

export interface PaymentEndpointRouteDeps {
  lookup: PaymentEndpointLookup;
  webhookEvents: WebhookEventStore;
  enqueuer: JobEnqueuer;
  metrics?: Metrics;
  cacheMs?: number;
  missCacheMs?: number;
  toleranceS?: number;
}

export const PAYMENT_ENDPOINT_PATH = '/webhooks/stripe/accounts/:endpointKey';

const BODY_LIMIT = 1024 * 1024;
const METRIC_PROVIDER = 'stripe-endpoint';
const MAX_CACHE_ENTRIES = 5000;

export class EndpointCache {
  private readonly entries = new Map<string, { endpoint: PaymentEndpoint | null; expires: number; fetched: number }>();

  constructor(
    private readonly lookup: PaymentEndpointLookup,
    private readonly hitMs: number,
    private readonly missMs: number,
    private readonly clock: () => number = Date.now,
  ) {}

  async resolve(endpointKey: string): Promise<PaymentEndpoint | null> {
    const now = this.clock();
    const cached = this.entries.get(endpointKey);
    if (cached && cached.expires > now) return cached.endpoint;
    return this.fetch(endpointKey, now);
  }

  async refresh(endpointKey: string): Promise<PaymentEndpoint | null | undefined> {
    const now = this.clock();
    const cached = this.entries.get(endpointKey);
    if (cached && now - cached.fetched < this.missMs) return undefined;
    return this.fetch(endpointKey, now);
  }

  private async fetch(endpointKey: string, now: number): Promise<PaymentEndpoint | null> {
    const endpoint = await this.lookup(endpointKey);
    if (this.entries.size >= MAX_CACHE_ENTRIES) this.evict(now);
    this.entries.set(endpointKey, { endpoint, expires: now + (endpoint === null ? this.missMs : this.hitMs), fetched: now });
    return endpoint;
  }

  private evict(now: number): void {
    for (const [key, entry] of this.entries) if (entry.expires <= now) this.entries.delete(key);
    if (this.entries.size < MAX_CACHE_ENTRIES) return;
    const oldest = this.entries.keys().next();
    if (!oldest.done) this.entries.delete(oldest.value);
  }
}

function verify(raw: Buffer, signature: string, secret: string, toleranceS: number): unknown {
  return Stripe.webhooks.constructEvent(raw, signature, secret, toleranceS);
}

export function paymentEndpointWebhookRoutes(deps: PaymentEndpointRouteDeps): FastifyPluginAsync {
  const cache = new EndpointCache(deps.lookup, deps.cacheMs ?? 300_000, deps.missCacheMs ?? 30_000);
  const toleranceS = deps.toleranceS ?? SIGNATURE_TOLERANCE_S;
  const count = (result: string): void => deps.metrics?.webhooks.inc({ provider: METRIC_PROVIDER, result });

  return async (app) => {
    app.removeAllContentTypeParsers();
    app.addContentTypeParser('*', { parseAs: 'buffer', bodyLimit: BODY_LIMIT }, (_request, body, done) => done(null, body));

    app.post<{ Params: { endpointKey: string } }>(PAYMENT_ENDPOINT_PATH, async (request, reply) => {
      const endpointKey = request.params.endpointKey;
      const signature = request.headers['stripe-signature'];
      const raw = request.body;
      if (!ENDPOINT_KEY_RE.test(endpointKey)) {
        count('unknown_endpoint');
        return reply.code(404).send({ error: 'not_found' });
      }
      if (typeof signature !== 'string' || signature === '' || !Buffer.isBuffer(raw) || raw.length === 0) {
        count('missing_signature');
        return reply.code(400).send({ error: 'missing_signature' });
      }

      let endpoint: PaymentEndpoint | null;
      try {
        endpoint = await cache.resolve(endpointKey);
      } catch (error) {
        count('lookup_failed');
        request.log.error(errorFields(error), 'payment endpoint lookup failed');
        return reply.code(503).send({ error: 'unavailable' });
      }
      if (endpoint === null) {
        count('unknown_endpoint');
        return reply.code(404).send({ error: 'not_found' });
      }

      let verified: unknown;
      try {
        verified = verify(raw, signature, endpoint.webhook_secret, toleranceS);
      } catch (error) {
        const rotated = await cache.refresh(endpointKey).catch(() => undefined);
        if (rotated && rotated.webhook_secret !== endpoint.webhook_secret) {
          try {
            verified = verify(raw, signature, rotated.webhook_secret, toleranceS);
            endpoint = rotated;
          } catch {
            verified = undefined;
          }
        }
        if (verified === undefined) {
          count('invalid_signature');
          request.log.warn({ accountId: endpoint.payment_account_id, ...errorFields(error) }, 'payment endpoint signature rejected');
          return reply.code(400).send({ error: 'invalid_signature' });
        }
      }
      const parsed = providerEventSchema.safeParse(verified);
      if (!parsed.success) {
        count('invalid_event');
        return reply.code(400).send({ error: 'invalid_event' });
      }
      const event = parsed.data;

      const provider = paymentEventProvider(endpoint.driver);
      const recorded = await deps.webhookEvents.record({
        provider,
        eventId: event.id,
        type: event.type,
        payload: { id: event.id, type: event.type, payment_account_id: endpoint.payment_account_id, workspace_id: endpoint.workspace_id },
      });
      if (recorded.processed) {
        count('duplicate');
        return reply.send({ received: true, duplicate: true });
      }

      const job: PaymentEventJob = {
        driver: endpoint.driver,
        payment_account_id: endpoint.payment_account_id,
        workspace_id: endpoint.workspace_id,
        event,
      };
      const jobId = providerEventJobId(endpoint.driver, event.id);
      const { created } = await deps.enqueuer.add(QUEUES.paymentEvents, JOB_NAMES.paymentEvent, job, jobId, { retryFailed: !recorded.inserted });
      count(recorded.inserted ? 'accepted' : 'redelivered');
      request.log.info(
        { accountId: endpoint.payment_account_id, workspaceId: endpoint.workspace_id, eventId: event.id, eventType: event.type, enqueued: created },
        'payment endpoint event accepted',
      );
      return reply.send({ received: true, duplicate: !recorded.inserted });
    });
  };
}
