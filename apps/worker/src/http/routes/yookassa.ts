import type { FastifyPluginAsync } from 'fastify';
import { z } from 'zod';
import type { WebhookEventStore } from '../../db/webhookEvents.js';
import { YOOKASSA_PROVIDER, yookassaEventId, yookassaNotificationSchema } from '../../jobs/billing.js';
import type { Metrics } from '../../metrics.js';
import type { JobEnqueuer } from '../../queues/enqueuer.js';
import { JOB_NAMES, QUEUES } from '../../queues/names.js';
import { clientIp, createAllowlist } from '../ipAllowlist.js';

export interface YooKassaRouteDeps {
  allowedIps: readonly string[];
  trustedProxyHops: number;
  webhookEvents: WebhookEventStore;
  enqueuer: JobEnqueuer;
  metrics?: Metrics;
}

export function yookassaWebhookRoutes(deps: YooKassaRouteDeps): FastifyPluginAsync {
  const allowed = createAllowlist(deps.allowedIps);

  return async (app) => {
    app.post('/webhooks/yookassa', async (request, reply) => {
      const ip = clientIp(request.socket.remoteAddress, request.headers['x-forwarded-for'], deps.trustedProxyHops);
      if (!allowed(ip)) {
        deps.metrics?.webhooks.inc({ provider: YOOKASSA_PROVIDER, result: 'forbidden_ip' });
        request.log.warn({ ip }, 'yookassa notification from a non-allowlisted address rejected');
        return reply.code(403).send({ error: 'forbidden' });
      }

      const parsed = yookassaNotificationSchema.safeParse(request.body);
      if (!parsed.success) {
        deps.metrics?.webhooks.inc({ provider: YOOKASSA_PROVIDER, result: 'invalid_payload' });
        request.log.warn({ issues: z.prettifyError(parsed.error) }, 'yookassa notification rejected');
        return reply.code(400).send({ error: 'invalid_payload' });
      }

      const notification = parsed.data;
      const eventId = yookassaEventId(notification.event, notification.object.id);
      const recorded = await deps.webhookEvents.record({
        provider: YOOKASSA_PROVIDER,
        eventId,
        type: notification.event,
        payload: notification,
      });
      if (recorded.processed) {
        deps.metrics?.webhooks.inc({ provider: YOOKASSA_PROVIDER, result: 'duplicate' });
        return reply.send({ received: true, duplicate: true });
      }

      const jobId = `yookassa-${notification.event.replace('.', '-')}-${notification.object.id}`;
      const { created } = await deps.enqueuer.add(QUEUES.billing, JOB_NAMES.yookassaNotification, notification, jobId, {
        retryFailed: !recorded.inserted,
      });
      deps.metrics?.webhooks.inc({ provider: YOOKASSA_PROVIDER, result: recorded.inserted ? 'accepted' : 'redelivered' });
      request.log.info({ eventId, enqueued: created }, 'yookassa notification accepted');
      return reply.send({ received: true, duplicate: !recorded.inserted });
    });
  };
}
