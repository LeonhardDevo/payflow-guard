import { UnrecoverableError } from 'bullmq';
import { z } from 'zod';
import { isRetryableError } from '../clients/errors.js';
import { errorFields, type Logger } from '../logger.js';
import type { OutboxJobData } from '../outbox/types.js';

export interface JobContext {
  queue: string;
  name: string;
  jobId: string;
  attempt: number;
  maxAttempts: number;
  isFinalAttempt: boolean;
  requestId: string;
  logger: Logger;
  progress?: unknown;
  reportProgress?: (value: number | object) => Promise<void>;
}

export type JobHandler = (data: unknown, ctx: JobContext) => Promise<unknown>;
export type HandlerMap = Record<string, JobHandler>;

export function permanent(message: string): UnrecoverableError {
  return new UnrecoverableError(message);
}

export function isPermanent(error: unknown): boolean {
  return error instanceof UnrecoverableError || !isRetryableError(error);
}

const envelopeSchema = z.object({
  outbox_id: z.string().min(1),
  topic: z.string().min(1),
  workspace_id: z.number().nullable().optional(),
  payload: z.unknown(),
});

export function parseOutboxJob<S extends z.ZodType>(data: unknown, schema: S): OutboxJobData<z.output<S>> {
  const envelope = envelopeSchema.safeParse(data);
  if (!envelope.success) throw permanent(`Invalid outbox envelope: ${z.prettifyError(envelope.error)}`);
  const payload = schema.safeParse(envelope.data.payload);
  if (!payload.success) {
    throw permanent(`Invalid payload for topic "${envelope.data.topic}": ${z.prettifyError(payload.error)}`);
  }
  return {
    outbox_id: envelope.data.outbox_id,
    topic: envelope.data.topic,
    workspace_id: envelope.data.workspace_id ?? null,
    payload: payload.data,
  };
}

export async function withGiveUp<T>(ctx: JobContext, fn: () => Promise<T>, onGiveUp: (error: unknown) => Promise<void>): Promise<T> {
  try {
    return await fn();
  } catch (error) {
    const stop = isPermanent(error);
    if (stop || ctx.isFinalAttempt) {
      await onGiveUp(error).catch((reportError: unknown) => ctx.logger.error(errorFields(reportError), 'give-up hook failed'));
      if (stop && !(error instanceof UnrecoverableError)) {
        throw permanent(error instanceof Error ? error.message : String(error));
      }
    }
    throw error;
  }
}

export function errorMessage(error: unknown): string {
  return (error instanceof Error ? error.message : String(error)).slice(0, 1000);
}

export const referenceSchema = z.object({
  type: z.string().min(1),
  id: z.union([z.string().min(1), z.number()]),
});
