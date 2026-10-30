import { type Composer, InlineKeyboard } from 'grammy';
import { isLocale, type Locale } from '../../i18n/index.js';
import { errorFields } from '../../logger.js';
import { escapeHtml } from '../format.js';
import { isPrivateChat, replyHtml, replyUnlinked, withSettingsLink } from '../helpers.js';
import type { BotContext, BotModule } from '../types.js';

const LINK_TOKEN_RE = /^[A-Za-z0-9_-]{8,64}$/;

async function handleStart(ctx: BotContext): Promise<void> {
  const token = typeof ctx.match === 'string' ? ctx.match.trim() : '';
  if (token !== '') {
    await linkAccount(ctx, token);
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
    const { html, keyboard } = withSettingsLink(ctx, ctx.t('start.welcome_unlinked'));
    await replyHtml(ctx, html, keyboard);
    return;
  }
  await replyHtml(
    ctx,
    ctx.t('start.welcome_linked', {
      name: escapeHtml(account.user.name),
      workspace: escapeHtml(account.workspace?.name ?? ctx.t('whoami.no_workspace')),
    }),
  );
}

async function linkAccount(ctx: BotContext, token: string): Promise<void> {
  const from = ctx.from;
  const chat = ctx.chat;
  if (!from || !chat) return;
  if (!isPrivateChat(ctx)) {
    await ctx.reply(ctx.t('common.private_only'));
    return;
  }
  if (!LINK_TOKEN_RE.test(token)) {
    const { html, keyboard } = withSettingsLink(ctx, ctx.t('start.invalid_token'));
    await replyHtml(ctx, html, keyboard);
    return;
  }
  let result;
  try {
    result = await ctx.services.web.linkTelegram(
      {
        token,
        telegram_user_id: from.id,
        chat_id: chat.id,
        username: from.username ?? null,
        language_code: from.language_code ?? null,
      },
      ctx.requestId,
    );
  } catch (error) {
    ctx.log.error(errorFields(error), 'telegram link request failed');
    await replyHtml(ctx, ctx.t('common.unavailable'));
    return;
  }
  if (!result.ok) {
    ctx.log.info({ reason: result.reason }, 'telegram link rejected');
    const key = result.reason === 'conflict' ? 'start.conflict' : 'start.invalid_token';
    const { html, keyboard } = withSettingsLink(ctx, ctx.t(key));
    await replyHtml(ctx, html, keyboard);
    return;
  }
  await ctx.services.accounts.invalidate(from.id);
  const preferred = await ctx.services.preferences.getLocale(from.id).catch(() => null);
  if (!preferred && result.locale) ctx.setLocale(result.locale);
  ctx.log.info({ chatId: chat.id }, 'telegram account linked');
  const name = escapeHtml(result.userName ?? from.first_name);
  await replyHtml(
    ctx,
    result.workspaceName
      ? ctx.t('start.linked_workspace', { name, workspace: escapeHtml(result.workspaceName) })
      : ctx.t('start.linked', { name }),
  );
}

async function handleHelp(ctx: BotContext): Promise<void> {
  const lines = [ctx.t('help.title'), '', ctx.t('help.intro'), '', ctx.t('help.commands')];
  const restricted = ctx.services.registry.commands.some((command) => command.permission !== undefined);
  const account = restricted ? await ctx.account().catch(() => null) : null;
  const granted = new Set(account?.permissions ?? []);
  for (const command of ctx.services.registry.commands) {
    if (command.permission !== undefined && !granted.has(command.permission) && !granted.has('*')) continue;
    lines.push(`/${command.command} — ${ctx.t(command.descriptionKey)}`);
  }
  await replyHtml(ctx, lines.join('\n'));
}

function languageKeyboard(ctx: BotContext): InlineKeyboard {
  return new InlineKeyboard().text(ctx.t('lang.button_ru'), 'lang:ru').text(ctx.t('lang.button_en'), 'lang:en');
}

async function handleLangCommand(ctx: BotContext): Promise<void> {
  await ctx.reply(ctx.t('lang.choose'), { reply_markup: languageKeyboard(ctx) });
}

async function handleLangChoice(ctx: BotContext, locale: Locale): Promise<void> {
  const from = ctx.from;
  if (!from) return;
  await ctx.services.preferences.setLocale(from.id, ctx.chat?.id ?? from.id, locale, from.username ?? null);
  ctx.setLocale(locale);
  await ctx.answerCallbackQuery({ text: ctx.t('lang.changed') });
  if (ctx.callbackQuery?.message) {
    await ctx.editMessageText(ctx.t('lang.changed'));
  } else {
    await ctx.reply(ctx.t('lang.changed'));
  }
}

async function handleWhoami(ctx: BotContext): Promise<void> {
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
  const permissions = account.permissions.length > 0 ? account.permissions.map(escapeHtml).join(', ') : ctx.t('whoami.none');
  await replyHtml(
    ctx,
    ctx.t('whoami.linked', {
      name: escapeHtml(account.user.name),
      workspace: escapeHtml(account.workspace?.name ?? ctx.t('whoami.no_workspace')),
      role: escapeHtml(account.role),
      plan: escapeHtml(account.workspace?.plan ?? '—'),
      locale: ctx.locale.toUpperCase(),
      permissions,
    }),
  );
}

export const coreModule: BotModule = {
  name: 'core',
  commands: [
    { command: 'start', descriptionKey: 'commands.start' },
    { command: 'help', descriptionKey: 'commands.help' },
    { command: 'lang', descriptionKey: 'commands.lang' },
    { command: 'whoami', descriptionKey: 'commands.whoami' },
  ],
  register(composer: Composer<BotContext>) {
    composer.command('start', handleStart);
    composer.command('help', handleHelp);
    composer.command('lang', handleLangCommand);
    composer.command('whoami', handleWhoami);
    composer.callbackQuery(/^lang:(ru|en)$/, async (ctx) => {
      const locale = ctx.match[1];
      if (isLocale(locale)) await handleLangChoice(ctx, locale);
    });
  },
};
