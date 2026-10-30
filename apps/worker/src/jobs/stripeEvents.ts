import { z } from 'zod';
import type { JobDeps } from './deps.js';
import { errorMessage, permanent, withGiveUp, type JobContext, type JobHandler } from './types.js';

export const STRIPE_PROVIDER = 'stripe';

export const stripeEventSchema = z
  .object({
    id: z.string().min(1),
    type: z.string().min(1),
    object: z.literal('event').optional(),
  })
  .passthrough();

export function createStripeEventHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const parsed = stripeEventSchema.safeParse(data);
    if (!parsed.success) throw permanent(`Invalid Stripe event: ${z.prettifyError(parsed.error)}`);
    const event = parsed.data;
    await withGiveUp(
      ctx,
      async () => {
        try {
          await deps.web.forwardStripeEvent(event, ctx.requestId);
        } catch (error) {
          await deps.webhookEvents.markFailed(STRIPE_PROVIDER, event.id, errorMessage(error)).catch(() => undefined);
          throw error;
        }
      },
      async (error) => {
        ctx.logger.error({ eventId: event.id, eventType: event.type, error: errorMessage(error) }, 'stripe event forwarding gave up');
      },
    );
    await deps.webhookEvents.markProcessed(STRIPE_PROVIDER, event.id);
    ctx.logger.info({ eventId: event.id, eventType: event.type }, 'stripe event forwarded to web');
    return { forwarded: event.id };
  };
}
