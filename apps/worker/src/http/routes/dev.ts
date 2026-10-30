import type { FastifyPluginAsync } from 'fastify';
import type { Update } from 'grammy/types';
import { z } from 'zod';
import type { BotHandle } from '../../bot/createBot.js';
import { buildDevUpdate, devUpdateShorthandSchema } from '../../bot/devUpdate.js';

export function devRoutes(deps: { bot: BotHandle }): FastifyPluginAsync {
  return async (app) => {
    app.post('/dev/telegram/update', async (request, reply) => {
      const body = request.body as Record<string, unknown> | null;
      let update: Update;
      if (body && typeof body === 'object' && typeof body.update_id === 'number' && ('message' in body || 'callback_query' in body)) {
        update = body as unknown as Update;
      } else {
        const parsed = devUpdateShorthandSchema.safeParse(body);
        if (!parsed.success) {
          return reply.code(422).send({ error: 'invalid_update', details: z.prettifyError(parsed.error) });
        }
        update = buildDevUpdate(parsed.data);
      }
      const outbound = await deps.bot.handleUpdateCaptured(update);
      return reply.send({ update_id: update.update_id, outbound });
    });

    app.get('/dev/telegram/outbox', async () => ({ outbound: deps.bot.capture.offlineCalls() }));

    app.delete('/dev/telegram/outbox', async (_request, reply) => {
      deps.bot.capture.clearOffline();
      return reply.code(204).send();
    });
  };
}
