import { z } from 'zod';
import { isPermanentSmtpError, type MailAddress, type MailAttachment } from '../mail/mailer.js';
import { clientMailDelayMs } from '../queues/backoff.js';
import { SENT_MARKER_TTL_S, type JobDeps } from './deps.js';
import { errorMessage, isPermanent, parseOutboxJob, permanent, referenceSchema, withGiveUp, type JobContext, type JobHandler } from './types.js';

const address = z.string().trim().min(3).max(320).refine((value) => value.includes('@'), 'must be an email address');
const displayName = z.string().trim().max(200).nullish();
const mailbox = z.union([address, z.object({ address, name: displayName })]);

export const ALLOWED_EXTRA_HEADERS = ['List-Unsubscribe', 'List-Unsubscribe-Post', 'Auto-Submitted'] as const;
export const V2_HEADERS = ['List-Unsubscribe', 'List-Unsubscribe-Post'] as const;
const headerValue = z.string().max(2000).regex(/^[^\r\n]*$/, 'must be a single line');
const base64 = z.string().regex(/^[A-Za-z0-9+/=\r\n]*$/, 'must be base64');

export const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;
export const MAX_INLINE_ATTACHMENT_BYTES = 256 * 1024;
export const ATTACHMENT_PATH_RE =
  /^\/internal\/v1\/(?:payflow\/invoices\/\d{1,18}\/pdf\?workspace_id=\d{1,18}|outbox\/attachments\/[A-Za-z0-9-]{1,64})$/;

const attachmentSchema = z.object({
  filename: z.string().trim().max(255).nullish(),
  content_type: z.string().trim().min(3).max(255).default('application/octet-stream'),
  content_base64: base64,
});

export const emailPayloadSchema = z
  .object({
    to: z.union([address, z.array(address).min(1).max(50)]),
    to_name: displayName,
    subject: z.string().trim().min(1).max(998),
    html: z.string().nullish(),
    text: z.string().nullish(),
    locale: z.enum(['ru', 'en']).nullish(),
    from: mailbox.nullish(),
    reply_to: mailbox.nullish(),
    attachments: z.array(attachmentSchema).max(20).nullish(),
    reference: referenceSchema,
    workspace_id: z.number().nullish(),
    headers: z.record(z.string(), headerValue).nullish(),
  })
  .refine((payload) => Boolean(payload.html || payload.text), { message: 'html or text is required' });
export type EmailPayload = z.infer<typeof emailPayloadSchema>;

const v2HeaderName = z
  .string()
  .refine((name) => (V2_HEADERS as readonly string[]).includes(name) || /^X-PF-[A-Za-z0-9-]{1,60}$/.test(name), 'header is not allowed');

const v2AttachmentSchema = z
  .object({
    filename: z.string().trim().min(1).max(255),
    content_type: z.string().trim().min(3).max(255),
    internal_path: z.string().regex(ATTACHMENT_PATH_RE, 'must be a signed web attachment path').optional(),
    content_base64: base64.optional(),
  })
  .refine((attachment) => (attachment.internal_path === undefined) !== (attachment.content_base64 === undefined), {
    message: 'exactly one of internal_path or content_base64 is required',
  })
  .refine((attachment) => attachment.content_base64 === undefined || decodedLength(attachment.content_base64) <= MAX_INLINE_ATTACHMENT_BYTES, {
    message: `inline attachments are limited to ${MAX_INLINE_ATTACHMENT_BYTES} bytes`,
  });

export const emailV2PayloadSchema = z
  .object({
    v: z.literal(2),
    to: address,
    to_name: displayName,
    cc: z.array(address).max(5).nullish(),
    from_name: z.string().trim().max(120).nullish(),
    reply_to: address.nullish(),
    subject: z.string().trim().min(1).max(998),
    html: z.string().nullish(),
    text: z.string().nullish(),
    locale: z.enum(['en', 'ru']).nullish(),
    headers: z.record(v2HeaderName, headerValue).nullish(),
    attachments: z.array(v2AttachmentSchema).max(20).nullish(),
    reference: referenceSchema,
    workspace_id: z.number().nullish(),
    retry_profile: z.enum(['default', 'client_mail']).nullish(),
  })
  .refine((payload) => Boolean(payload.html || payload.text), { message: 'html or text is required' });
export type EmailV2Payload = z.infer<typeof emailV2PayloadSchema>;

const versionProbe = z.object({ v: z.unknown().optional() }).passthrough();

const anyEmailSchema = z.unknown().transform((value, ctx): NormalizedEmailInput => {
  const probe = versionProbe.safeParse(value);
  const schema = probe.success && probe.data.v !== undefined ? emailV2PayloadSchema : emailPayloadSchema;
  const parsed = schema.safeParse(value);
  if (!parsed.success) {
    for (const issue of parsed.error.issues) ctx.addIssue({ code: 'custom', message: issue.message, path: issue.path });
    return z.NEVER;
  }
  return 'v' in parsed.data ? { version: 2, payload: parsed.data } : { version: 1, payload: parsed.data };
});

type NormalizedEmailInput = { version: 1; payload: EmailPayload } | { version: 2; payload: EmailV2Payload };

interface PendingAttachment {
  filename: string;
  contentType: string;
  base64?: string;
  internalPath?: string;
}

export interface NormalizedEmail {
  version: 1 | 2;
  from: MailAddress;
  to: MailAddress | MailAddress[];
  cc: string[];
  replyTo: MailAddress | null;
  subject: string;
  html: string | null;
  text: string | null;
  locale: 'ru' | 'en' | null;
  headers: Record<string, string>;
  attachments: PendingAttachment[];
  reference: z.infer<typeof referenceSchema>;
  retryProfile: 'default' | 'client_mail';
}

function decodedLength(value: string): number {
  const clean = value.replace(/[\r\n]/g, '');
  const padding = clean.endsWith('==') ? 2 : clean.endsWith('=') ? 1 : 0;
  return Math.floor((clean.length * 3) / 4) - padding;
}

function toMailbox(value: z.infer<typeof mailbox>): MailAddress {
  return typeof value === 'string' ? value : { address: value.address, name: value.name ?? '' };
}

export function mailboxAddress(value: string): string {
  const match = /<([^<>\s]+@[^<>\s]+)>/.exec(value);
  return (match?.[1] ?? value).trim();
}

export function normalizeEmail(input: NormalizedEmailInput, settings: JobDeps['settings']): NormalizedEmail {
  if (input.version === 2) {
    const payload = input.payload;
    return {
      version: 2,
      from: { name: payload.from_name ?? settings.productName ?? '', address: mailboxAddress(settings.mailFrom) },
      to: payload.to_name ? { address: payload.to, name: payload.to_name } : payload.to,
      cc: payload.cc ?? [],
      replyTo: payload.reply_to ?? null,
      subject: payload.subject,
      html: payload.html ?? null,
      text: payload.text ?? null,
      locale: payload.locale ?? null,
      headers: { ...(payload.headers ?? {}) },
      attachments: (payload.attachments ?? []).map((attachment) => ({
        filename: attachment.filename,
        contentType: attachment.content_type,
        ...(attachment.content_base64 !== undefined ? { base64: attachment.content_base64 } : {}),
        ...(attachment.internal_path !== undefined ? { internalPath: attachment.internal_path } : {}),
      })),
      reference: payload.reference,
      retryProfile: payload.retry_profile ?? 'default',
    };
  }
  const payload = input.payload;
  const allowed: readonly string[] = ALLOWED_EXTRA_HEADERS;
  return {
    version: 1,
    from: payload.from ? toMailbox(payload.from) : settings.mailFrom,
    to: Array.isArray(payload.to) ? payload.to : payload.to_name ? { address: payload.to, name: payload.to_name } : payload.to,
    cc: [],
    replyTo: payload.reply_to ? toMailbox(payload.reply_to) : null,
    subject: payload.subject,
    html: payload.html ?? null,
    text: payload.text ?? null,
    locale: payload.locale ?? null,
    headers: Object.fromEntries(Object.entries(payload.headers ?? {}).filter(([name]) => allowed.includes(name))),
    attachments: (payload.attachments ?? []).map((attachment, index) => ({
      filename: attachment.filename ?? `attachment-${index + 1}`,
      contentType: attachment.content_type,
      base64: attachment.content_base64,
    })),
    reference: payload.reference,
    retryProfile: 'default',
  };
}

async function resolveAttachments(deps: JobDeps, email: NormalizedEmail, requestId: string): Promise<MailAttachment[]> {
  let total = 0;
  const resolved: MailAttachment[] = [];
  for (const attachment of email.attachments) {
    let content: Buffer;
    if (attachment.internalPath !== undefined) {
      const remaining = MAX_ATTACHMENT_BYTES - total;
      if (remaining <= 0) throw permanent(`attachments exceed ${MAX_ATTACHMENT_BYTES} bytes`);
      content = (await deps.web.fetchAttachment(attachment.internalPath, remaining, requestId)).content;
    } else {
      content = Buffer.from(attachment.base64 ?? '', 'base64');
    }
    total += content.byteLength;
    if (total > MAX_ATTACHMENT_BYTES) throw permanent(`attachments exceed ${MAX_ATTACHMENT_BYTES} bytes`);
    resolved.push({ filename: attachment.filename, contentType: attachment.contentType, content });
  }
  return resolved;
}

export function parseEmailJob(data: unknown): { outbox_id: string; email: NormalizedEmailInput } {
  const job = parseOutboxJob(data, anyEmailSchema);
  return { outbox_id: job.outbox_id, email: job.payload };
}

export function createEmailHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const job = parseEmailJob(data);
    const email = normalizeEmail(job.email, deps.settings);
    const markerKey = `sent:email:${job.outbox_id}`;

    const providerMessageId = await withGiveUp(
      ctx,
      async () => {
        const already = await deps.sentMarkers.get(markerKey);
        if (already !== null) {
          ctx.logger.info({ providerMessageId: already }, 'email already sent; skipping SMTP');
          return already;
        }
        try {
          const attachments = await resolveAttachments(deps, email, ctx.requestId);
          const result = await deps.mailer.send({
            from: email.from,
            to: email.to,
            subject: email.subject,
            ...(email.cc.length > 0 ? { cc: email.cc } : {}),
            ...(email.html ? { html: email.html } : {}),
            ...(email.text ? { text: email.text } : {}),
            ...(email.replyTo ? { replyTo: email.replyTo } : {}),
            ...(attachments.length > 0 ? { attachments } : {}),
            messageId: `<${job.outbox_id}@${deps.settings.productSlug}.worker>`,
            headers: {
              'X-Entity-Ref-ID': job.outbox_id,
              'X-Reference': `${email.reference.type}:${email.reference.id}`,
              ...(email.locale ? { 'Content-Language': email.locale } : {}),
              ...email.headers,
            },
          });
          await deps.sentMarkers.set(markerKey, result.messageId, SENT_MARKER_TTL_S);
          ctx.logger.info({ providerMessageId: result.messageId, reference: email.reference }, 'email sent');
          return result.messageId;
        } catch (error) {
          const failure = isPermanentSmtpError(error) ? permanent(`SMTP rejected the message: ${errorMessage(error)}`) : error;
          if (email.retryProfile === 'client_mail' && !ctx.isFinalAttempt && !isPermanent(failure)) {
            await reportDeferred(deps, email, ctx, failure);
          }
          throw failure;
        }
      },
      async (error) => {
        await deps.web.reportDelivery({ reference: email.reference, channel: 'email', status: 'failed', error: errorMessage(error) }, ctx.requestId);
      },
    );

    await deps.web.reportDelivery(
      { reference: email.reference, channel: 'email', status: 'sent', provider_message_id: providerMessageId },
      ctx.requestId,
    );
    return { providerMessageId };
  };
}

async function reportDeferred(deps: JobDeps, email: NormalizedEmail, ctx: JobContext, error: unknown): Promise<void> {
  const nextRetryAt = new Date(Date.now() + clientMailDelayMs(ctx.attempt)).toISOString();
  deps.hooks?.emailDeferred?.(email.retryProfile);
  ctx.logger.warn({ reference: email.reference, attempt: ctx.attempt, nextRetryAt, error: errorMessage(error) }, 'client e-mail deferred');
  await deps.web
    .reportDelivery(
      {
        reference: email.reference,
        channel: 'email',
        status: 'deferred',
        attempt: ctx.attempt,
        next_retry_at: nextRetryAt,
        error: errorMessage(error),
      },
      ctx.requestId,
    )
    .catch((reportError: unknown) => ctx.logger.warn({ error: errorMessage(reportError) }, 'deferred delivery report failed'));
}
