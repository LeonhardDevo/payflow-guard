import { type Composer, InlineKeyboard, type MiddlewareFn } from 'grammy';
import { errorFields } from '../../logger.js';
import { escapeHtml } from '../format.js';
import { isPrivateChat, replyHtml } from '../helpers.js';
import type { BotContext, BotModule } from '../types.js';

export const PRIVACY_PATH = '/legal/privacy';

const UNLINK_CONFIRM = 'privacy:unlink';
const UNLINK_CANCEL = 'privacy:cancel';

export function privacyUrl(ctx: BotContext): string {
  return `${ctx.services.settings.webPublicUrl}${PRIVACY_PATH}`;
}

export function privacyNotice(): MiddlewareFn<BotContext> {
  return async (ctx, next) => {
    const from = ctx.from;
    const chat = ctx.chat;
    if (from && chat && ctx.message && isPrivateChat(ctx)) {
      let first = false;
      try {
        first = await ctx.services.preferences.markPrivacyNoticeShown(from.id, chat.id, from.username ?? null);
      } catch (error) {
        ctx.log.warn(errorFields(error), 'privacy notice state unavailable');
      }
      if (first) {
        await ctx.reply(ctx.t('privacy.notice', { url: privacyUrl(ctx) }), { link_preview_options: { is_disabled: true } });
      }
    }
    await next();
  };
}

async function handlePrivacy(ctx: BotContext): Promise<void> {
  await replyHtml(ctx, ctx.t('privacy.details', { url: escapeHtml(privacyUrl(ctx)) }));
}

async function handleUnlink(ctx: BotContext): Promise<void> {
  if (!isPrivateChat(ctx)) {
    await ctx.reply(ctx.t('common.private_only'));
    return;
  }
  let account;
  try {
    account = await ctx.account();
  } catch (error) {
    ctx.log.warn(errorFields(error), 'telegram context lookup failed');
    await replyHtml(ctx, ctx.t('common.unavailable'));
    return;
  }
  if (!account) {
    await replyHtml(ctx, ctx.t('privacy.not_linked'));
    return;
  }
  const keyboard = new InlineKeyboard().text(ctx.t('privacy.unlink_button'), UNLINK_CONFIRM).text(ctx.t('privacy.cancel_button'), UNLINK_CANCEL);
  await replyHtml(ctx, ctx.t('privacy.unlink_confirm'), keyboard);
}

async function confirmUnlink(ctx: BotContext): Promise<void> {
  const from = ctx.from;
  if (!from) return;
  let unlinked: boolean;
  try {
    unlinked = await ctx.services.web.unlinkTelegram(from.id, ctx.requestId);
  } catch (error) {
    ctx.log.error(errorFields(error), 'telegram unlink failed');
    await ctx.answerCallbackQuery({ text: ctx.t('common.unavailable'), show_alert: true });
    return;
  }
  await ctx.services.accounts.invalidate(from.id);
  ctx.log.info({ unlinked }, 'telegram unlink requested');
  await ctx.answerCallbackQuery();
  await ctx.editMessageText(ctx.t(unlinked ? 'privacy.unlinked' : 'privacy.not_linked'), { parse_mode: 'HTML' });
}

async function cancelUnlink(ctx: BotContext): Promise<void> {
  await ctx.answerCallbackQuery();
  await ctx.editMessageText(ctx.t('privacy.cancelled'));
}

export const privacyModule: BotModule = {
  name: 'privacy',
  commands: [
    { command: 'privacy', descriptionKey: 'commands.privacy' },
    { command: 'unlink', descriptionKey: 'commands.unlink' },
  ],
  register(composer: Composer<BotContext>) {
    composer.command('privacy', handlePrivacy);
    composer.command('unlink', handleUnlink);
    composer.callbackQuery(UNLINK_CONFIRM, confirmUnlink);
    composer.callbackQuery(UNLINK_CANCEL, cancelUnlink);
  },
};
