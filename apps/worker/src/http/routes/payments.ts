import type { FastifyPluginAsync } from 'fastify';
import Stripe from 'stripe';
import type { WebhookEventStore } from '../../db/webhookEvents.js';
import { stripeAccountProvider } from '../../jobs/billing.js';
import { errorFields } from '../../logger.js';
import type { Metrics } from '../../metrics.js';
import type { JobEnqueuer } from '../../queues/enqueuer.js';
import { JOB_NAMES, QUEUES } from '../../queues/names.js';

export type PaymentAccountSecretLookup = (accountId: number) => Promise<string | null>;

export interface PaymentsRouteDeps {
  secretFor: PaymentAccountSecretLookup;
  webhookEvents: WebhookEventStore;
  enqueuer: JobEnqueuer;
  metrics?: Metrics;
  secretCacheMs?: number;
}

const BODY_LIMIT = 1024 * 1024;
const PROVIDER = 'stripe-account';

export function paymentWebhookRoutes(deps: PaymentsRouteDeps): FastifyPluginAsync {
  const cache = new Map<number, { secret: string | null; expires: number }>();
  const ttl = deps.secretCacheMs ?? 300_000;

  const secretFor = async (accountId: number): Promise<string | null> => {
    const cached = cache.get(accountId);
    if (cached && cached.expires > Date.now()) return cached.secret;
    const secret = await deps.secretFor(accountId);
    cache.set(accountId, { secret, expires: Date.now() + (secret === null ? Math.min(ttl, 30_000) : ttl) });
    return secret;
  };

  return async (app) => {
    app.removeAllContentTypeParsers();
    app.addContentTypeParser('*', { parseAs: 'buffer', bodyLimit: BODY_LIMIT }, (_request, body, done) => done(null, body));

    app.post<{ Params: { accountId: string } }>('/webhooks/payments/stripe/:accountId', async (request, reply) => {
      const accountId = Number(request.params.accountId);
      const signature = request.headers['stripe-signature'];
      const raw = request.body;
      if (!Number.isSafeInteger(accountId) || accountId <= 0) return reply.code(404).send({ error: 'not_found' });
      if (typeof signature !== 'string' || signature === '' || !Buffer.isBuffer(raw) || raw.length === 0) {
        deps.metrics?.webhooks.inc({ provider: PROVIDER, result: 'missing_signature' });
        return reply.code(400).send({ error: 'missing_signature' });
      }

      const secret = await secretFor(accountId);
      if (secret === null) {
        deps.metrics?.webhooks.inc({ provider: PROVIDER, result: 'unknown_account' });
        return reply.code(404).send({ error: 'not_found' });
      }

      let event: { id: string; type: string };
      try {
        event = Stripe.webhooks.constructEvent(raw, signature, secret);
      } catch (error) {
        deps.metrics?.webhooks.inc({ provider: PROVIDER, result: 'invalid_signature' });
        request.log.warn({ accountId, ...errorFields(error) }, 'stripe account webhook signature rejected');
        return reply.code(400).send({ error: 'invalid_signature' });
      }

      const provider = stripeAccountProvider(accountId);
      const recorded = await deps.webhookEvents.record({ provider, eventId: event.id, type: event.type, payload: event });
      if (recorded.processed) {
        deps.metrics?.webhooks.inc({ provider: PROVIDER, result: 'duplicate' });
        return reply.send({ received: true, duplicate: true });
      }

      const { created } = await deps.enqueuer.add(
        QUEUES.billing,
        JOB_NAMES.stripeAccountEvent,
        { accountId, event },
        `acct-${accountId}-${event.id}`,
        { retryFailed: !recorded.inserted },
      );
      deps.metrics?.webhooks.inc({ provider: PROVIDER, result: recorded.inserted ? 'accepted' : 'redelivered' });
      request.log.info({ accountId, eventId: event.id, eventType: event.type, enqueued: created }, 'stripe account webhook accepted');
      return reply.send({ received: true, duplicate: !recorded.inserted });
    });
  };
}
