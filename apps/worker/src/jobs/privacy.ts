import { z } from 'zod';
import type { JobDeps } from './deps.js';
import { errorMessage, parseOutboxJob, withGiveUp, type JobContext, type JobHandler } from './types.js';

export const exportRequestedSchema = z.object({
  export_id: z.number().int().positive(),
});

export const workspacePurgeSchema = z.object({
  workspace_id: z.number().int().positive(),
});

export const userPurgeSchema = z.object({
  user_id: z.number().int().positive(),
  telegram_user_ids: z.array(z.number().int().positive()).default([]),
});

export function createExportRequestedHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const { payload } = parseOutboxJob(data, exportRequestedSchema);
    return withGiveUp(
      ctx,
      async () => {
        const result = await deps.web.buildExport(payload.export_id, ctx.requestId);
        ctx.logger.info({ exportId: payload.export_id, status: result.status }, 'data export built');
        return result;
      },
      async (error) => {
        ctx.logger.error({ exportId: payload.export_id, error: errorMessage(error) }, 'data export gave up');
      },
    );
  };
}

export function createPrivacyPurgeHandler(deps: JobDeps): JobHandler {
  return async (_data: unknown, ctx: JobContext) => {
    const result = await deps.web.privacyPurge(ctx.requestId);
    if (result.skipped) {
      ctx.logger.warn('privacy purge skipped: another run holds the lock');
      return { skipped: true };
    }
    const webhookEvents = await deps.webhookEvents.purgeOlderThan(result.webhook_events_days);
    let aiMemory: 'pruned' | 'skipped' | 'failed' = 'skipped';
    if (result.ai_memory) {
      try {
        await deps.ai.pruneMemory(result.ai_memory, ctx.requestId);
        aiMemory = 'pruned';
      } catch (error) {
        aiMemory = 'failed';
        ctx.logger.error({ error: errorMessage(error) }, 'ai memory prune failed');
      }
    }
    ctx.logger.info({ deletions: result.deletions, retention: result.retention, webhookEvents, aiMemory }, 'privacy purge completed');
    return { deletions: result.deletions, retention: { ...result.retention, webhook_events: webhookEvents }, ai_memory: aiMemory };
  };
}

export function createWorkspacePurgeHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const { payload } = parseOutboxJob(data, workspacePurgeSchema);
    await withGiveUp(
      ctx,
      () => deps.ai.purgeWorkspace(payload.workspace_id, ctx.requestId),
      async (error) => {
        ctx.logger.error({ workspaceId: payload.workspace_id, error: errorMessage(error) }, 'workspace purge in ai gave up');
      },
    );
    ctx.logger.info({ workspaceId: payload.workspace_id }, 'workspace purged in ai');
    return { purged: true };
  };
}

export function createUserPurgeHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const { payload } = parseOutboxJob(data, userPurgeSchema);
    const telegramChats = await deps.telegramChats.forget(payload.telegram_user_ids);
    await withGiveUp(
      ctx,
      () => deps.ai.purgeUser(payload.user_id, ctx.requestId),
      async (error) => {
        ctx.logger.error({ userId: payload.user_id, error: errorMessage(error) }, 'user purge in ai gave up');
      },
    );
    ctx.logger.info({ userId: payload.user_id, telegramChats }, 'user purged in worker and ai');
    return { purged: true, telegram_chats: telegramChats };
  };
}
