import { createHash } from 'node:crypto';
import { z } from 'zod';

export const ENDPOINT_DRIVERS = ['stripe', 'fake'] as const;
export type EndpointDriver = (typeof ENDPOINT_DRIVERS)[number];

export const ENDPOINT_KEY_RE = /^[A-Za-z0-9]{16,64}$/;
export const SIGNATURE_TOLERANCE_S = 300;

export const paymentEndpointSchema = z
  .object({
    payment_account_id: z.number().int().positive(),
    workspace_id: z.number().int().positive(),
    webhook_secret: z.string().min(1),
    driver: z.enum(ENDPOINT_DRIVERS).default('stripe'),
  })
  .passthrough();
export type PaymentEndpoint = z.infer<typeof paymentEndpointSchema>;

export const providerEventSchema = z.object({ id: z.string().min(1).max(255), type: z.string().min(1).max(100), object: z.literal('event') }).passthrough();
export type ProviderEvent = z.infer<typeof providerEventSchema>;

export const paymentEventJobSchema = z.object({
  driver: z.enum(ENDPOINT_DRIVERS),
  payment_account_id: z.number().int().positive(),
  workspace_id: z.number().int().positive(),
  event: providerEventSchema,
});
export type PaymentEventJob = z.infer<typeof paymentEventJobSchema>;

export const PAYMENT_EVENT_RESULTS = ['processed', 'ignored', 'duplicate', 'failed'] as const;

export const paymentEventResponseSchema = z
  .object({ result: z.enum(PAYMENT_EVENT_RESULTS).nullish().transform((value) => value ?? null) })
  .passthrough();
export type PaymentEventResponse = z.infer<typeof paymentEventResponseSchema>;

export const paymentEventProvider = (driver: EndpointDriver): string => `payment-${driver}`;

export function providerEventJobId(driver: EndpointDriver, eventId: string): string {
  const safe = /^[A-Za-z0-9_-]{1,200}$/.test(eventId) ? eventId : createHash('sha256').update(eventId).digest('hex').slice(0, 40);
  return `pevt-${driver}-${safe}`;
}
