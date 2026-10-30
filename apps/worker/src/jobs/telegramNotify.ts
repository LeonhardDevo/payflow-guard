import { GrammyError, type InlineKeyboard } from 'grammy';
import { z } from 'zod';
import { escapeHtml, toTelegramHtml } from '../bot/format.js';
import { renderButtons, withLines } from '../bot/helpers.js';
import type { NotifyButton } from '../bot/types.js';
import type { TelegramContext } from '../clients/web.js';
import { DEFAULT_LOCALE, type Locale } from '../i18n/index.js';
import { SENT_MARKER_TTL_S, type JobDeps } from './deps.js';
import { errorMessage, parseOutboxJob, permanent, referenceSchema, withGiveUp, type JobContext, type JobHandler } from './types.js';

const buttonSchema = z
  .object({
    text: z.string().trim().min(1).max(64),
    url: z.string().url().optional(),
    callback: z.string().trim().min(1).max(200).optional(),
  })
  .refine((button) => Boolean(button.url ?? button.callback), { message: 'button needs url or callback' });

export const telegramNotifyPayloadSchema = z
  .object({
    user_id: z.number().int().positive().optional(),
    chat_id: z.union([z.number().int(), z.string().regex(/^-?\d+$|^@\w+$/)]).optional(),
    text_html: z.string().min(1).optional(),
    template: z.string().min(1).optional(),
    params: z.record(z.string(), z.unknown()).optional(),
    locale: z.enum(['ru', 'en']).optional(),
    buttons: z.array(buttonSchema).max(10).optional(),
    reference: referenceSchema,
  })
  .refine((payload) => payload.user_id !== undefined || payload.chat_id !== undefined, { message: 'user_id or chat_id is required' })
  .refine((payload) => Boolean(payload.text_html ?? payload.template), { message: 'text_html or template is required' });
export type TelegramNotifyPayload = z.infer<typeof telegramNotifyPayloadSchema>;

export function isPermanentTelegramError(error: unknown): boolean {
  return error instanceof GrammyError && (error.error_code === 400 || error.error_code === 403);
}

interface Recipient {
  chatId: number | string;
  telegramUserId: number | null;
  locale: Locale;
  context: TelegramContext | null;
}

export function createTelegramNotifyHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const job = parseOutboxJob(data, telegramNotifyPayloadSchema);
    const { payload } = job;
    const markerKey = `sent:telegram:${job.outbox_id}`;
    const report = async (status: 'sent' | 'failed', extra: { error?: string; provider_message_id?: string }): Promise<void> => {
      await deps.web.reportDelivery({ reference: payload.reference, channel: 'telegram', status, ...extra }, ctx.requestId);
    };

    const outcome = await withGiveUp(
      ctx,
      async (): Promise<{ messageId: string } | { notLinked: true }> => {
        const already = await deps.sentMarkers.get(markerKey);
        if (already !== null) return { messageId: already };

        const recipient = await resolveRecipient(deps, payload, ctx);
        if (!recipient) return { notLinked: true };

        const { html, buttons } = render(deps, payload, recipient.locale);
        const rendered = await buildKeyboard(deps, buttons, recipient, job.workspace_id);
        try {
          const message = await deps.telegram.sendMessage(recipient.chatId, withLines(html, rendered.lines), rendered.keyboard);
          const messageId = String(message.message_id);
          await deps.sentMarkers.set(markerKey, messageId, SENT_MARKER_TTL_S);
          ctx.logger.info({ chatId: recipient.chatId, messageId, reference: payload.reference }, 'telegram notification sent');
          return { messageId };
        } catch (error) {
          if (isPermanentTelegramError(error)) throw permanent(`Telegram rejected the message: ${errorMessage(error)}`);
          throw error;
        }
      },
      async (error) => report('failed', { error: errorMessage(error) }),
    );

    if ('notLinked' in outcome) {
      ctx.logger.info({ userId: payload.user_id, reference: payload.reference }, 'telegram notification skipped: user not linked');
      await report('failed', { error: 'telegram_not_linked' });
      return { skipped: 'telegram_not_linked' };
    }
    await report('sent', { provider_message_id: outcome.messageId });
    return { messageId: outcome.messageId };
  };
}

async function resolveRecipient(deps: JobDeps, payload: TelegramNotifyPayload, ctx: JobContext): Promise<Recipient | null> {
  if (payload.chat_id !== undefined) {
    const numeric = typeof payload.chat_id === 'number' ? payload.chat_id : Number(payload.chat_id);
    const telegramUserId = Number.isSafeInteger(numeric) && numeric > 0 ? numeric : null;
    return { chatId: payload.chat_id, telegramUserId, locale: payload.locale ?? DEFAULT_LOCALE, context: null };
  }
  const context = await deps.web.telegramContext({ userId: payload.user_id ?? 0 }, ctx.requestId);
  const chatId = context?.telegram?.chat_id ?? context?.telegram?.user_id ?? null;
  if (!context || chatId === null) return null;
  return {
    chatId,
    telegramUserId: context.telegram?.user_id ?? null,
    locale: payload.locale ?? context.user.locale ?? DEFAULT_LOCALE,
    context,
  };
}

function render(deps: JobDeps, payload: TelegramNotifyPayload, locale: Locale): { html: string; buttons: NotifyButton[] } {
  if (payload.text_html) {
    return { html: toTelegramHtml(payload.text_html), buttons: payload.buttons ?? [] };
  }
  const name = payload.template ?? '';
  const template = deps.registry.notification(name);
  if (!template) throw permanent(`Unknown notification template "${name}"`);
  const rendered = template(payload.params ?? {}, {
    locale,
    t: deps.translator.forLocale(locale),
    escape: escapeHtml,
  });
  const html = toTelegramHtml(rendered.html);
  return {
    html: html === '' ? deps.translator.t(locale, 'notify.fallback') : html,
    buttons: [...(rendered.buttons ?? []), ...(payload.buttons ?? [])],
  };
}

async function buildKeyboard(
  deps: JobDeps,
  buttons: NotifyButton[],
  recipient: Recipient,
  workspaceId: number | null,
): Promise<{ keyboard: InlineKeyboard | undefined; lines: string[] }> {
  return renderButtons(
    buttons,
    {
      telegramUserId: recipient.telegramUserId,
      chatId: typeof recipient.chatId === 'number' ? recipient.chatId : null,
      workspaceId,
      userId: recipient.context?.user.id ?? null,
      locale: recipient.locale,
    },
    deps.actions,
    deps.settings.notificationActionTtlS,
  );
}
