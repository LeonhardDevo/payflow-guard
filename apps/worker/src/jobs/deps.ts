import type { InlineKeyboardMarkup, Update } from 'grammy/types';
import type { AiApi } from '../clients/ai.js';
import type { WebApi } from '../clients/web.js';
import type { ChatPreferences } from '../db/telegramChats.js';
import type { WebhookEventStore } from '../db/webhookEvents.js';
import type { Translator } from '../i18n/index.js';
import type { Mailer } from '../mail/mailer.js';
import type { ActionStore, KeyValueCache } from '../bot/stores.js';
import type { BotRegistry } from '../bot/types.js';

export interface TelegramSender {
  sendMessage(chatId: number | string, html: string, replyMarkup?: InlineKeyboardMarkup): Promise<{ message_id: number }>;
}

export interface JobDeps {
  web: WebApi;
  ai: AiApi;
  mailer: Mailer;
  telegram: TelegramSender;
  sentMarkers: KeyValueCache;
  webhookEvents: WebhookEventStore;
  telegramChats: Pick<ChatPreferences, 'forget'>;
  bot: { handleUpdate(update: Update): Promise<void> };
  registry: BotRegistry;
  translator: Translator;
  actions: ActionStore;
  settings: {
    productSlug: string;
    productName?: string;
    mailFrom: string;
    notificationActionTtlS: number;
  };
  hooks?: {
    emailDeferred?: (profile: string) => void;
  };
}

export const SENT_MARKER_TTL_S = 7 * 24 * 3600;
