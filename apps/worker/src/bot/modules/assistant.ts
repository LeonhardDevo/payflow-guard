import { type Composer, GrammyError, type InlineKeyboard } from 'grammy';
import type { ChatResponse } from '../../clients/ai.js';
import { isNotFound } from '../../clients/errors.js';
import { errorFields } from '../../logger.js';
import { buildActionKeyboard, renderAnswerHtml } from '../answer.js';
import { escapeHtml, splitTelegramHtml, stripHtml } from '../format.js';
import { isPrivateChat, replyHtml, replyUnlinked } from '../helpers.js';
import type { StoredAction } from '../stores.js';
import type { BotContext, BotModule } from '../types.js';

const MAX_QUESTION_LENGTH = 4000;
const TYPING_INTERVAL_MS = 4500;
const CALLBACK_TEXT_LIMIT = 190;

function startTyping(ctx: BotContext): () => void {
  const send = (): void => {
    ctx.replyWithChatAction('typing').catch(() => undefined);
  };
  send();
  const timer = setInterval(send, TYPING_INTERVAL_MS);
  timer.unref();
  return () => clearInterval(timer);
}

export async function answerQuestion(ctx: BotContext, rawQuestion: string): Promise<void> {
  const from = ctx.from;
  const chat = ctx.chat;
  if (!from || !chat) return;
  const question = rawQuestion.trim().slice(0, MAX_QUESTION_LENGTH);
  if (question === '') return;

  let account;
  try {
    account = await ctx.account();
  } catch (error) {
    ctx.log.warn(errorFields(error), 'telegram context lookup failed');
    await replyHtml(ctx, ctx.t('common.unavailable'));
    return;
  }
  if (!account) {
    await replyUnlinked(ctx);
    return;
  }
  if (!account.workspace) {
    await replyHtml(ctx, ctx.t('assistant.no_workspace'));
    return;
  }
  const quota = account.ai_quota;
  if (quota && quota.limit !== null && quota.used >= quota.limit) {
    await replyHtml(ctx, ctx.t('assistant.quota_exceeded', { used: quota.used, limit: quota.limit }));
    return;
  }

  const { services } = ctx;
  const stopTyping = startTyping(ctx);
  const startedAt = Date.now();
  let response: ChatResponse;
  try {
    response = await services.ai.chat(
      {
        workspace_id: account.workspace.id,
        user: { id: account.user.id, role: account.role, locale: ctx.locale, permissions: account.permissions },
        channel: 'telegram',
        conversation_id: `tg-${chat.id}`,
        message: question,
        ai_external_allowed: account.workspace.ai_external_allowed,
      },
      ctx.requestId,
    );
  } catch (error) {
    ctx.log.error(errorFields(error), 'ai chat failed');
    await replyHtml(ctx, ctx.t('common.unavailable'));
    return;
  } finally {
    stopTyping();
  }

  const html = renderAnswerHtml(response, ctx.locale, services.translator, services.settings.webPublicUrl);
  const keyboard = await buildActionKeyboard(
    response.actions,
    { telegramUserId: from.id, chatId: chat.id, workspaceId: account.workspace.id, userId: account.user.id, locale: ctx.locale },
    services.actions,
    services.settings.actionTtlS,
  );
  await sendLongHtml(ctx, html, keyboard);
  ctx.log.info(
    { refused: response.refused, confidence: response.confidence, citations: response.citations.length, latencyMs: Date.now() - startedAt },
    'assistant answered',
  );

  if (services.settings.logAiQueries) {
    await services.web
      .logAiQuery(
        {
          workspace_id: account.workspace.id,
          user_id: account.user.id,
          channel: 'telegram',
          question,
          answer: response.answer_markdown || stripHtml(response.answer_html),
          refused: response.refused,
          confidence: response.confidence,
          citations: response.citations,
          tools_used: response.tools_used,
          latency_ms: response.latency_ms ?? Date.now() - startedAt,
          provider: response.provider ?? null,
          model: response.model ?? null,
        },
        ctx.requestId,
      )
      .catch((error: unknown) => ctx.log.warn(errorFields(error), 'ai query log failed'));
  }
}

export async function sendLongHtml(ctx: BotContext, html: string, keyboard?: InlineKeyboard): Promise<void> {
  const parts = splitTelegramHtml(html);
  for (const [index, part] of parts.entries()) {
    const markup = index === parts.length - 1 ? keyboard : undefined;
    try {
      await replyHtml(ctx, part, markup);
    } catch (error) {
      if (!(error instanceof GrammyError && error.error_code === 400 && /parse entities/i.test(error.description))) throw error;
      ctx.log.warn({ description: error.description }, 'telegram rejected HTML; sending plain text');
      await ctx.reply(stripHtml(part), markup ? { reply_markup: markup } : {});
    }
  }
}

async function handleActionCallback(ctx: BotContext, id: string): Promise<void> {
  const from = ctx.from;
  if (!from) return;
  const { services } = ctx;
  const action = await services.actions.get(id);
  if (!action) {
    await ctx.answerCallbackQuery({ text: ctx.t('actions.expired'), show_alert: true });
    return;
  }
  const ownerMismatch =
    action.telegram_user_id !== null
      ? action.telegram_user_id !== from.id
      : action.chat_id !== null && action.chat_id !== (ctx.chat?.id ?? from.id);
  if (ownerMismatch) {
    await ctx.answerCallbackQuery({ text: ctx.t('actions.forbidden'), show_alert: true });
    return;
  }
  ctx.log.info({ actionType: action.type }, 'bot action pressed');
  const handler = services.registry.action(action.type);
  if (handler) {
    await ctx.answerCallbackQuery();
    await handler(ctx, action);
    return;
  }
  await forwardActionToWeb(ctx, action);
}

async function forwardActionToWeb(ctx: BotContext, action: StoredAction): Promise<void> {
  const from = ctx.from;
  if (!from) return;
  try {
    const result = await ctx.services.web.telegramAction(
      {
        type: action.type,
        payload: action.payload,
        telegram_user_id: from.id,
        chat_id: ctx.chat?.id ?? action.chat_id ?? from.id,
        workspace_id: action.workspace_id,
        user_id: action.user_id,
        locale: ctx.locale,
      },
      ctx.requestId,
    );
    const toast = result.message ?? (result.ok ? ctx.t('actions.done') : ctx.t('actions.failed'));
    await ctx.answerCallbackQuery({ text: toast.slice(0, CALLBACK_TEXT_LIMIT), show_alert: result.alert ?? !result.ok });
    if (result.message_html) await sendLongHtml(ctx, result.message_html);
  } catch (error) {
    const unavailable = isNotFound(error);
    ctx.log.warn({ actionType: action.type, ...errorFields(error) }, 'bot action forward failed');
    await ctx.answerCallbackQuery({ text: ctx.t(unavailable ? 'actions.unavailable' : 'actions.failed'), show_alert: true });
  }
}

export const assistantModule: BotModule = {
  name: 'assistant',
  actions: {
    ask: async (ctx, action) => {
      const question = typeof action.payload.question === 'string' ? action.payload.question : '';
      if (question !== '') {
        await replyHtml(ctx, `<i>${escapeHtml(question)}</i>`);
        await answerQuestion(ctx, question);
      }
    },
  },
  register(composer: Composer<BotContext>) {
    composer.callbackQuery(/^a:([A-Za-z0-9]{6,32})$/, async (ctx) => {
      await handleActionCallback(ctx, ctx.match[1] ?? '');
    });
    composer.on('callback_query:data', async (ctx) => {
      await ctx.answerCallbackQuery({ text: ctx.t('actions.expired') });
    });
    composer.on('message:text', async (ctx) => {
      if (!isPrivateChat(ctx)) return;
      const text = ctx.message.text;
      if (text.startsWith('/')) {
        await replyHtml(ctx, ctx.t('assistant.unknown_command'));
        return;
      }
      await answerQuestion(ctx, text);
    });
    composer.on('message', async (ctx) => {
      if (isPrivateChat(ctx)) await replyHtml(ctx, ctx.t('assistant.text_only'));
    });
  },
};
