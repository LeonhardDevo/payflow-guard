import type { FastifyPluginAsync } from 'fastify';
import Stripe from 'stripe';
import type { WebhookEventStore } from '../../db/webhookEvents.js';
import { STRIPE_PROVIDER } from '../../jobs/stripeEvents.js';
import { errorFields } from '../../logger.js';
import type { Metrics } from '../../metrics.js';
import type { JobEnqueuer } from '../../queues/enqueuer.js';
import { JOB_NAMES, QUEUES } from '../../queues/names.js';

export interface StripeRouteDeps {
  webhookSecret: string;
  webhookEvents: WebhookEventStore;
  enqueuer: JobEnqueuer;
  metrics?: Metrics;
}

const BODY_LIMIT = 1024 * 1024;

export function stripeWebhookRoutes(deps: StripeRouteDeps): FastifyPluginAsync {
  return async (app) => {
    app.removeAllContentTypeParsers();
    app.addContentTypeParser('*', { parseAs: 'buffer', bodyLimit: BODY_LIMIT }, (_request, body, done) => done(null, body));

    app.post('/webhooks/stripe', async (request, reply) => {
      const signature = request.headers['stripe-signature'];
      const raw = request.body;
      if (typeof signature !== 'string' || signature === '') {
        deps.metrics?.webhooks.inc({ provider: STRIPE_PROVIDER, result: 'missing_signature' });
        return reply.code(400).send({ error: 'missing_signature' });
      }
      if (!Buffer.isBuffer(raw) || raw.length === 0) {
        deps.metrics?.webhooks.inc({ provider: STRIPE_PROVIDER, result: 'empty_body' });
        return reply.code(400).send({ error: 'empty_body' });
      }

      let event: { id: string; type: string };
      try {
        event = Stripe.webhooks.constructEvent(raw, signature, deps.webhookSecret);
      } catch (error) {
        deps.metrics?.webhooks.inc({ provider: STRIPE_PROVIDER, result: 'invalid_signature' });
        request.log.warn(errorFields(error), 'stripe webhook signature rejected');
        return reply.code(400).send({ error: 'invalid_signature' });
      }

      const recorded = await deps.webhookEvents.record({
        provider: STRIPE_PROVIDER,
        eventId: event.id,
        type: event.type,
        payload: event,
      });
      if (recorded.processed) {
        deps.metrics?.webhooks.inc({ provider: STRIPE_PROVIDER, result: 'duplicate' });
        request.log.info({ eventId: event.id, eventType: event.type }, 'stripe webhook duplicate ignored');
        return reply.send({ received: true, duplicate: true });
      }
      const { created } = await deps.enqueuer.add(QUEUES.stripeEvents, JOB_NAMES.stripeEvent, event, event.id, {
        retryFailed: !recorded.inserted,
      });
      deps.metrics?.webhooks.inc({ provider: STRIPE_PROVIDER, result: recorded.inserted ? 'accepted' : 'redelivered' });
      request.log.info({ eventId: event.id, eventType: event.type, enqueued: created }, 'stripe webhook accepted');
      return reply.send({ received: true, duplicate: !recorded.inserted });
    });
  };
}
