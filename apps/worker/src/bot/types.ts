import type { Composer, Context } from 'grammy';
import type { AiApi } from '../clients/ai.js';
import type { TelegramContext, WebApi } from '../clients/web.js';
import type { ChatPreferences } from '../db/telegramChats.js';
import type { Locale, MessageTree, Params, Translator } from '../i18n/index.js';
import type { Logger } from '../logger.js';
import type { Metrics } from '../metrics.js';
import type { AccountResolver } from './accounts.js';
import type { ActionStore, RateLimiter, StoredAction } from './stores.js';

export interface BotSettings {
  productName: string;
  botUsername: string;
  webPublicUrl: string;
  rateLimit: number;
  rateWindowS: number;
  actionTtlS: number;
  logAiQueries: boolean;
}

export interface BotServices {
  settings: BotSettings;
  translator: Translator;
  web: WebApi;
  ai: AiApi;
  actions: ActionStore;
  rateLimiter: RateLimiter;
  preferences: ChatPreferences;
  accounts: AccountResolver;
  logger: Logger;
  metrics?: Metrics;
  registry: BotRegistry;
}

export interface BotFlavor {
  services: BotServices;
  requestId: string;
  locale: Locale;
  t: (key: string, params?: Params) => string;
  account: () => Promise<TelegramContext | null>;
  setLocale: (locale: Locale) => void;
  log: Logger;
}

export type BotContext = Context & BotFlavor;

export interface NotifyButton {
  text: string;
  url?: string;
  callback?: string;
}

export interface NotificationHelpers {
  locale: Locale;
  t: (key: string, params?: Params) => string;
  escape: (value: string) => string;
}

export type NotificationTemplate = (
  params: Record<string, unknown>,
  helpers: NotificationHelpers,
) => { html: string; buttons?: NotifyButton[] };

export type ActionHandler = (ctx: BotContext, action: StoredAction) => Promise<void>;

export interface BotCommand {
  command: string;
  descriptionKey: string;
  permission?: string;
}

export interface BotModule {
  name: string;
  commands?: BotCommand[];
  messages?: Partial<Record<Locale, MessageTree>>;
  register?: (composer: Composer<BotContext>) => void;
  actions?: Record<string, ActionHandler>;
  notifications?: Record<string, NotificationTemplate>;
}

export interface BotRegistry {
  readonly modules: readonly BotModule[];
  readonly commands: readonly BotCommand[];
  action(type: string): ActionHandler | undefined;
  notification(name: string): NotificationTemplate | undefined;
}
