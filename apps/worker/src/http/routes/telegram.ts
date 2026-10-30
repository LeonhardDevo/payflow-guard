import type { FastifyPluginAsync } from 'fastify';
import type { Update } from 'grammy/types';
import { enqueueUpdate } from '../../bot/runtime.js';
import { safeEqual } from '../../clients/signing.js';
import type { Metrics } from '../../metrics.js';
import type { JobEnqueuer } from '../../queues/enqueuer.js';

export interface TelegramRouteDeps {
  enabled: boolean;
  secret: string | undefined;
  enqueuer: JobEnqueuer;
  metrics?: Metrics;
}

export const TELEGRAM_SECRET_HEADER = 'x-telegram-bot-api-secret-token';

export function telegramWebhookRoutes(deps: TelegramRouteDeps): FastifyPluginAsync {
  return async (app) => {
    app.post('/webhooks/telegram', { bodyLimit: 1024 * 1024 }, async (request, reply) => {
      if (!deps.enabled || !deps.secret) {
        return reply.code(404).send({ error: 'telegram_webhook_disabled' });
      }
      const provided = request.headers[TELEGRAM_SECRET_HEADER];
      if (typeof provided !== 'string' || !safeEqual(provided, deps.secret)) {
        deps.metrics?.webhooks.inc({ provider: 'telegram', result: 'unauthorized' });
        return reply.code(401).send({ error: 'unauthorized' });
      }
      const update = request.body as Partial<Update> | null;
      if (!update || typeof update !== 'object' || typeof update.update_id !== 'number') {
        deps.metrics?.webhooks.inc({ provider: 'telegram', result: 'invalid' });
        return reply.code(400).send({ error: 'invalid_update' });
      }
      const { created } = await enqueueUpdate(deps.enqueuer, update as Update);
      deps.metrics?.webhooks.inc({ provider: 'telegram', result: created ? 'accepted' : 'duplicate' });
      return reply.send({ ok: true });
    });
  };
}
