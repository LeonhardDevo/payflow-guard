import { z } from 'zod';
import { paymentEventJobSchema, paymentEventProvider } from '../payments/endpoints.js';
import type { JobDeps } from './deps.js';
import { errorMessage, permanent, withGiveUp, type JobHandler } from './types.js';

export function createPaymentEventHandler(deps: JobDeps): JobHandler {
  return async (data, ctx) => {
    const parsed = paymentEventJobSchema.safeParse(data);
    if (!parsed.success) throw permanent(`Invalid payment provider event: ${z.prettifyError(parsed.error)}`);
    const job = parsed.data;
    const provider = paymentEventProvider(job.driver);
    const response = await withGiveUp(
      ctx,
      async () => {
        try {
          return await deps.web.forwardPaymentEvent(job, ctx.requestId);
        } catch (error) {
          await deps.webhookEvents.markFailed(provider, job.event.id, errorMessage(error)).catch(() => undefined);
          throw error;
        }
      },
      async (error) => {
        ctx.logger.error({ provider, eventId: job.event.id, accountId: job.payment_account_id, error: errorMessage(error) }, 'payment event forwarding gave up');
      },
    );
    await deps.webhookEvents.markProcessed(provider, job.event.id);
    ctx.logger.info(
      { driver: job.driver, accountId: job.payment_account_id, workspaceId: job.workspace_id, eventId: job.event.id, eventType: job.event.type, result: response.result },
      'payment provider event forwarded to web',
    );
    return { forwarded: job.event.id, result: response.result };
  };
}
