import type { FastifyInstance } from 'fastify';
import { body, callerOf, requestIdOf, type RagDomainRoutes } from '../../http/routes.js';
import type { RagService } from '../../service.js';
import { draftRequestSchema, ReminderDraftService } from './draft.js';

export const REMINDER_DRAFT_PATH = '/v1/payflow/reminder-draft';

export const reminderDraftRoutes: RagDomainRoutes = (app: FastifyInstance, rag: RagService) => {
  const service = new ReminderDraftService(rag.manager, { localLlmProvider: rag.localLlmProvider });
  app.post(REMINDER_DRAFT_PATH, async (request, reply) => {
    if (callerOf(request) !== 'web') return reply.code(403).send({ error: 'caller_not_allowed' });
    const input = body(request, draftRequestSchema);
    return service.draft(input, requestIdOf(request));
  });
};
