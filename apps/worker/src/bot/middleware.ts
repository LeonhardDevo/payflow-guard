import { randomUUID } from 'node:crypto';
import { GrammyError, HttpError, type MiddlewareFn } from 'grammy';
import type { TelegramContext } from '../clients/web.js';
import { DEFAULT_LOCALE, localeFromLanguageCode, type Locale } from '../i18n/index.js';
import { errorFields } from '../logger.js';
import type { BotContext, BotServices } from './types.js';

export function contextSetup(services: BotServices): MiddlewareFn<BotContext> {
  return async (ctx, next) => {
    const requestId = randomUUID();
    let memo: Promise<TelegramContext | null> | null = null;
    ctx.services = services;
    ctx.requestId = requestId;
    ctx.log = services.logger.child({ updateId: ctx.update.update_id, requestId, tgUserId: ctx.from?.id });
    ctx.account = () => {
      const from = ctx.from;
      if (!from) return Promise.resolve(null);
      memo ??= services.accounts.get(from.id, requestId);
      return memo;
    };
    ctx.setLocale = (locale: Locale) => {
      ctx.locale = locale;
      ctx.t = services.translator.forLocale(locale);
    };
    ctx.setLocale(localeFromLanguageCode(ctx.from?.language_code) ?? DEFAULT_LOCALE);
    services.metrics?.botUpdates.inc({ kind: updateKind(ctx) });
    await next();
  };
}

export function isTransientTelegramError(error: unknown): boolean {
  if (error instanceof HttpError) return true;
  if (error instanceof GrammyError) return error.error_code === 429 || error.error_code >= 500;
  return false;
}

export function errorBoundary(): MiddlewareFn<BotContext> {
  return async (ctx, next) => {
    try {
      await next();
    } catch (error) {
      if (isTransientTelegramError(error)) throw error;
      ctx.log.error(errorFields(error), 'bot handler failed');
      await replyError(ctx).catch(() => undefined);
    }
  };
}

async function replyError(ctx: BotContext): Promise<void> {
  if (ctx.callbackQuery) {
    await ctx.answerCallbackQuery({ text: ctx.t('common.error'), show_alert: true });
    return;
  }
  if (ctx.chat) await ctx.reply(ctx.t('common.error'), { parse_mode: 'HTML' });
}

export function localeResolver(): MiddlewareFn<BotContext> {
  return async (ctx, next) => {
    const from = ctx.from;
    if (from) {
      const preferred = await ctx.services.preferences.getLocale(from.id).catch(() => null);
      if (preferred) {
        ctx.setLocale(preferred);
      } else {
        const account = await ctx.account().catch(() => null);
        if (account?.user.locale) ctx.setLocale(account.user.locale);
      }
    }
    await next();
  };
}

export function rateLimit(): MiddlewareFn<BotContext> {
  return async (ctx, next) => {
    const chatId = ctx.chat?.id ?? ctx.from?.id;
    const counted = Boolean(ctx.message || ctx.callbackQuery);
    if (chatId === undefined || !counted) return next();
    const { rateLimit: limit, rateWindowS } = ctx.services.settings;
    const hits = await ctx.services.rateLimiter.hit(`chat:${chatId}`, rateWindowS).catch(() => 0);
    if (hits <= limit) return next();
    ctx.services.metrics?.botUpdates.inc({ kind: 'rate_limited' });
    ctx.log.warn({ chatId, hits }, 'bot rate limit exceeded');
    if (ctx.callbackQuery) {
      await ctx.answerCallbackQuery({ text: ctx.t('common.rate_limited') });
    } else if (hits === limit + 1) {
      await ctx.reply(ctx.t('common.rate_limited'));
    }
  };
}

function updateKind(ctx: BotContext): string {
  if (ctx.message?.text?.startsWith('/')) return 'command';
  if (ctx.message?.text) return 'text';
  if (ctx.message) return 'message_other';
  if (ctx.callbackQuery) return 'callback';
  return 'other';
}
