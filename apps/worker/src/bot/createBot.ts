import { Bot, Composer } from 'grammy';
import type { Update, UserFromGetMe } from 'grammy/types';
import { errorFields, type Logger } from '../logger.js';
import { TelegramCapture, staticBotInfo, type OutboundCall } from './capture.js';
import { contextSetup, errorBoundary, localeResolver, rateLimit } from './middleware.js';
import { privacyNotice } from './modules/privacy.js';
import type { BotContext, BotServices } from './types.js';

export function grammySignal(signal: AbortSignal): Parameters<Bot['init']>[0] {
  return signal as unknown as Parameters<Bot['init']>[0];
}

export const FAKE_TOKEN = '0:offline-fake-token';

export interface CreateBotOptions {
  token: string | undefined;
  apiRoot: string;
  botUsername: string;
  productName: string;
}

export interface BotHandle {
  readonly bot: Bot<BotContext>;
  readonly capture: TelegramCapture;
  readonly offline: boolean;
  handleUpdate(update: Update): Promise<void>;
  handleUpdateCaptured(update: Update): Promise<OutboundCall[]>;
  init(logger: Logger): Promise<UserFromGetMe>;
}

export function createBot(services: BotServices, options: CreateBotOptions): BotHandle {
  const offline = !options.token;
  const fallbackInfo = staticBotInfo(options.botUsername, options.productName);
  const bot = new Bot<BotContext>(options.token ?? FAKE_TOKEN, {
    ...(offline ? { botInfo: fallbackInfo } : {}),
    client: { apiRoot: options.apiRoot },
  });
  const capture = new TelegramCapture(() => (bot.isInited() ? bot.botInfo : fallbackInfo));
  bot.api.config.use(capture.transformer(offline));

  bot.use(contextSetup(services));
  bot.use(errorBoundary());
  bot.use(localeResolver());
  bot.use(rateLimit());
  bot.use(privacyNotice());
  for (const module of services.registry.modules) {
    const composer = new Composer<BotContext>();
    module.register?.(composer);
    bot.use(composer);
  }

  return {
    bot,
    capture,
    offline,
    async handleUpdate(update) {
      await bot.handleUpdate(update);
    },
    async handleUpdateCaptured(update) {
      const { outbound } = await capture.capture(() => bot.handleUpdate(update));
      return outbound;
    },
    async init(logger) {
      if (bot.isInited()) return bot.botInfo;
      try {
        await bot.init(grammySignal(AbortSignal.timeout(10_000)));
        logger.info({ username: bot.botInfo.username }, 'telegram bot identity resolved');
      } catch (error) {
        logger.warn(errorFields(error), 'telegram getMe failed; using static bot info');
        bot.botInfo = fallbackInfo;
      }
      return bot.botInfo;
    },
  };
}
