import { InlineKeyboard } from 'grammy';
import type { ChatAction, ChatResponse, Citation } from '../clients/ai.js';
import type { Locale, Params, Translator } from '../i18n/index.js';
import { buttonLabel, escapeHtml, isPublicHttpUrl, markdownToTelegramHtml, toTelegramHtml } from './format.js';
import { ACTION_CALLBACK_PREFIX, type ActionStore, type StoredAction } from './stores.js';

export const MAX_ACTION_BUTTONS = 6;

export const DOCUMENT_TOOLS: ReadonlySet<string> = new Set(['search_documents']);

export function renderAnswerHtml(
  response: ChatResponse,
  locale: Locale,
  translator: Translator,
  webPublicUrl: string,
): string {
  const t = (key: string, params?: Params): string => translator.t(locale, key, params);
  let body = response.answer_html.trim() !== '' ? toTelegramHtml(response.answer_html) : markdownToTelegramHtml(response.answer_markdown);
  if (body === '') body = response.refused ? t('assistant.refused_hint') : t('assistant.empty_answer');
  if (!response.refused && response.tools_used.some((tool) => !DOCUMENT_TOOLS.has(tool))) body = `${body}\n\n<i>${t('assistant.live_data')}</i>`;
  const sources = renderSources(response.citations, locale, translator, webPublicUrl);
  return sources === '' ? body : `${body}\n\n${sources}`;
}

export function renderSources(citations: readonly Citation[], locale: Locale, translator: Translator, webPublicUrl: string): string {
  if (citations.length === 0) return '';
  const lines = [translator.plural(locale, 'assistant.sources', citations.length)];
  citations.forEach((citation, index) => {
    const url = absoluteUrl(citation.url ?? null, webPublicUrl);
    const title = escapeHtml(citation.title);
    const label = url && isPublicHttpUrl(url) ? `<a href="${escapeHtml(url)}">${title}</a>` : title;
    const page = typeof citation.page === 'number' ? `, ${translator.t(locale, 'assistant.page', { page: citation.page })}` : '';
    lines.push(`[${index + 1}] ${label}${page}`);
  });
  return lines.join('\n');
}

function absoluteUrl(url: string | null, base: string): string | null {
  if (!url) return null;
  if (/^https?:\/\//i.test(url)) return url;
  if (url.startsWith('/')) return `${base}${url}`;
  return null;
}

export interface ActionOwner {
  telegramUserId: number;
  chatId: number;
  workspaceId: number | null;
  userId: number | null;
  locale: Locale;
}

export async function buildActionKeyboard(
  actions: readonly ChatAction[],
  owner: ActionOwner,
  store: ActionStore,
  ttlS: number,
): Promise<InlineKeyboard | undefined> {
  if (actions.length === 0) return undefined;
  const keyboard = new InlineKeyboard();
  for (const action of actions.slice(0, MAX_ACTION_BUTTONS)) {
    const label = buttonLabel(action.label);
    const url = typeof action.payload.url === 'string' ? action.payload.url : null;
    if (url && isPublicHttpUrl(url)) {
      keyboard.url(label, url).row();
      continue;
    }
    const stored: StoredAction = {
      type: action.type,
      payload: action.payload,
      telegram_user_id: owner.telegramUserId,
      chat_id: owner.chatId,
      workspace_id: owner.workspaceId,
      user_id: owner.userId,
      locale: owner.locale,
      created_at: Date.now(),
    };
    const id = await store.put(stored, ttlS);
    keyboard.text(label, `${ACTION_CALLBACK_PREFIX}${id}`).row();
  }
  return keyboard;
}
