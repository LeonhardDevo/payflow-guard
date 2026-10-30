import { z } from 'zod';
import type { JobDeps } from './deps.js';
import { errorMessage, parseOutboxJob, withGiveUp, type JobContext, type JobHandler } from './types.js';

const documentId = z.union([z.number().int().positive(), z.string().regex(/^\d+$|^[0-9a-f-]{36}$/i)]);

export const documentUploadedSchema = z.object({
  document_id: documentId,
  workspace_id: z.number().int().positive(),
  title: z.string().optional(),
  mime: z.string().optional(),
  metadata: z.record(z.string(), z.unknown()).optional(),
  ai_external_allowed: z.boolean().default(false),
});

export const documentDeletedSchema = z.object({
  document_id: documentId,
  workspace_id: z.number().int().positive(),
});

export const workspaceReembedSchema = z.object({
  workspace_id: z.number().int().positive(),
  ai_external_allowed: z.boolean().default(false),
});

export function createDocumentUploadedHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const { payload } = parseOutboxJob(data, documentUploadedSchema);
    return withGiveUp(
      ctx,
      async () => {
        const result = await deps.ai.ingestDocument(
          payload.document_id,
          {
            workspace_id: payload.workspace_id,
            ai_external_allowed: payload.ai_external_allowed,
            ...(payload.title !== undefined ? { title: payload.title } : {}),
            ...(payload.mime !== undefined ? { mime: payload.mime } : {}),
            metadata: payload.metadata ?? {},
          },
          ctx.requestId,
        );
        ctx.logger.info({ documentId: payload.document_id, result }, 'document ingested');
        return result;
      },
      async (error) => {
        await deps.web.reportDocumentStatus(payload.document_id, { status: 'failed', error: errorMessage(error) }, ctx.requestId);
      },
    );
  };
}

export function createDocumentDeletedHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const { payload } = parseOutboxJob(data, documentDeletedSchema);
    await withGiveUp(
      ctx,
      () => deps.ai.deleteDocument(payload.document_id, payload.workspace_id, ctx.requestId),
      async (error) => {
        ctx.logger.error({ documentId: payload.document_id, error: errorMessage(error) }, 'document chunks could not be deleted');
      },
    );
    ctx.logger.info({ documentId: payload.document_id }, 'document chunks deleted');
    return { deleted: true };
  };
}

export function createWorkspaceReembedHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const { payload } = parseOutboxJob(data, workspaceReembedSchema);
    await withGiveUp(
      ctx,
      () => deps.ai.reembedWorkspace(payload.workspace_id, payload.ai_external_allowed, ctx.requestId),
      async (error) => {
        ctx.logger.error({ workspaceId: payload.workspace_id, error: errorMessage(error) }, 'workspace re-embed gave up');
      },
    );
    ctx.logger.info({ workspaceId: payload.workspace_id }, 'workspace re-embed requested');
    return { reembedded: true };
  };
}
