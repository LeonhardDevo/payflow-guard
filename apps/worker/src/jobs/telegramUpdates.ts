import type { Update } from 'grammy/types';
import type { JobDeps } from './deps.js';
import { permanent, type JobContext, type JobHandler } from './types.js';

export function createTelegramUpdateHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const updateId = (data as { update_id?: unknown } | null)?.update_id;
    if (typeof updateId !== 'number') throw permanent('Telegram update without numeric update_id');
    await deps.bot.handleUpdate(data as Update);
    ctx.logger.debug({ updateId }, 'telegram update handled');
    return { updateId };
  };
}
