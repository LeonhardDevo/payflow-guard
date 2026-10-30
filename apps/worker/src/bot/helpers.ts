import { InlineKeyboard } from 'grammy';
import type { InlineKeyboardButton } from 'grammy/types';
import type { Locale } from '../i18n/index.js';
import { buttonLabel, escapeHtml, isPublicHttpUrl } from './format.js';
import { ACTION_CALLBACK_PREFIX, type ActionStore, type StoredAction } from './stores.js';
import type { BotContext, NotifyButton } from './types.js';

export const INTEGRATIONS_PATH = '/settings/integrations';

export function settingsUrl(ctx: BotContext): string {
  return `${ctx.services.settings.webPublicUrl}${INTEGRATIONS_PATH}`;
}

export function isPrivateChat(ctx: BotContext): boolean {
  return ctx.chat?.type === 'private';
}

export function withSettingsLink(ctx: BotContext, html: string): { html: string; keyboard?: InlineKeyboard } {
  const url = settingsUrl(ctx);
  if (isPublicHttpUrl(url)) {
    return { html, keyboard: new InlineKeyboard().url(ctx.t('start.open_settings'), url) };
  }
  return { html: `${html}\n\n${ctx.t('unlinked.settings_url', { url: escapeHtml(url) })}` };
}

export async function replyHtml(ctx: BotContext, html: string, keyboard?: InlineKeyboard): Promise<void> {
  await ctx.reply(html, {
    parse_mode: 'HTML',
    link_preview_options: { is_disabled: true },
    ...(keyboard ? { reply_markup: keyboard } : {}),
  });
}

export async function replyUnlinked(ctx: BotContext): Promise<void> {
  const { html, keyboard } = withSettingsLink(ctx, ctx.t('unlinked.message'));
  await replyHtml(ctx, html, keyboard);
}

export const MAX_BUTTONS = 10;

export function splitCallback(callback: string): { type: string; arg: string | null } {
  const separator = callback.indexOf(':');
  return separator === -1 ? { type: callback, arg: null } : { type: callback.slice(0, separator), arg: callback.slice(separator + 1) };
}

export interface ButtonOwner {
  telegramUserId: number | null;
  chatId: number | null;
  workspaceId: number | null;
  userId: number | null;
  locale: Locale;
}

export function storedActionFor(callback: string, owner: ButtonOwner): StoredAction {
  const { type, arg } = splitCallback(callback);
  return {
    type,
    payload: { value: callback, arg },
    telegram_user_id: owner.telegramUserId,
    chat_id: owner.chatId,
    workspace_id: owner.workspaceId,
    user_id: owner.userId,
    locale: owner.locale,
    created_at: Date.now(),
  };
}

export async function storeActionButton(ctx: BotContext, text: string, callback: string, ttlS: number): Promise<{ text: string; callback_data: string }> {
  const owner: ButtonOwner = {
    telegramUserId: ctx.from?.id ?? null,
    chatId: ctx.chat?.id ?? null,
    workspaceId: null,
    userId: null,
    locale: ctx.locale,
  };
  const id = await ctx.services.actions.put(storedActionFor(callback, owner), ttlS);
  return { text: buttonLabel(text), callback_data: `${ACTION_CALLBACK_PREFIX}${id}` };
}

export interface RenderedButtons {
  keyboard: InlineKeyboard | undefined;
  lines: string[];
}

export async function renderButtons(
  buttons: readonly NotifyButton[],
  owner: ButtonOwner,
  store: ActionStore,
  ttlS: number,
): Promise<RenderedButtons> {
  const rows: InlineKeyboardButton[][] = [];
  const lines: string[] = [];
  for (const button of buttons.slice(0, MAX_BUTTONS)) {
    const label = buttonLabel(button.text);
    if (button.url && isPublicHttpUrl(button.url)) {
      rows.push([InlineKeyboard.url(label, button.url)]);
    } else if (button.callback) {
      const id = await store.put(storedActionFor(button.callback, owner), ttlS);
      rows.push([InlineKeyboard.text(label, `${ACTION_CALLBACK_PREFIX}${id}`)]);
    } else if (button.url && /^https?:\/\//i.test(button.url)) {
      lines.push(`${escapeHtml(label)}: ${escapeHtml(button.url)}`);
    }
  }
  return { keyboard: rows.length > 0 ? new InlineKeyboard(rows) : undefined, lines };
}

export function withLines(html: string, lines: readonly string[]): string {
  return lines.length === 0 ? html : `${html}\n\n${lines.join('\n')}`;
}
