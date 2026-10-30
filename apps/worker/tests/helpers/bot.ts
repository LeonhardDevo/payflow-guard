import type { FastifyInstance } from 'fastify';
import { AccountResolver } from '../../src/bot/accounts.js';
import type { OutboundCall } from '../../src/bot/capture.js';
import { createBot, type BotHandle } from '../../src/bot/createBot.js';
import { defaultModules } from '../../src/bot/modules/index.js';
import { createRegistry } from '../../src/bot/registry.js';
import { MemoryActionStore, MemoryCache, MemoryRateLimiter } from '../../src/bot/stores.js';
import type { BotModule, BotServices } from '../../src/bot/types.js';
import type { TelegramContext } from '../../src/clients/web.js';
import { MemoryChatPreferences } from '../../src/db/telegramChats.js';
import { MemoryWebhookEventStore } from '../../src/db/webhookEvents.js';
import { buildServer } from '../../src/http/server.js';
import { Translator } from '../../src/i18n/index.js';
import { createSilentLogger } from '../../src/logger.js';
import { Metrics } from '../../src/metrics.js';
import { MemoryEnqueuer } from '../../src/queues/enqueuer.js';
import { fakeAi, fakeWeb } from './fakes.js';

export const PRODUCT = { name: 'Acme Desk', slug: 'acme' };

export interface TestBot {
  handle: BotHandle;
  services: BotServices;
  web: ReturnType<typeof fakeWeb>;
  ai: ReturnType<typeof fakeAi>;
  actions: MemoryActionStore;
  preferences: MemoryChatPreferences;
  contexts: Map<number, TelegramContext>;
}

export function createTestBot(
  options: {
    modules?: BotModule[];
    rateLimit?: number;
    webPublicUrl?: string;
    contexts?: Map<number, TelegramContext>;
    privacyNotice?: boolean;
  } = {},
): TestBot {
  const contexts = options.contexts ?? new Map<number, TelegramContext>();
  const web = fakeWeb(contexts);
  const ai = fakeAi();
  const translator = new Translator(PRODUCT.name);
  const registry = createRegistry(options.modules ?? defaultModules(), translator);
  const actions = new MemoryActionStore();
  const preferences = new MemoryChatPreferences({ privacyNoticeSeen: options.privacyNotice !== true });
  const services: BotServices = {
    settings: {
      productName: PRODUCT.name,
      botUsername: 'acme_bot',
      webPublicUrl: options.webPublicUrl ?? 'http://127.0.0.1:8100',
      rateLimit: options.rateLimit ?? 20,
      rateWindowS: 60,
      actionTtlS: 3600,
      logAiQueries: true,
    },
    translator,
    web,
    ai,
    actions,
    rateLimiter: new MemoryRateLimiter(),
    preferences,
    accounts: new AccountResolver(web, new MemoryCache(), 60),
    logger: createSilentLogger(),
    registry,
  };
  const handle = createBot(services, { token: undefined, apiRoot: 'https://api.telegram.org', botUsername: 'acme_bot', productName: PRODUCT.name });
  return { handle, services, web, ai, actions, preferences, contexts };
}

export async function devServer(bot: BotHandle): Promise<FastifyInstance> {
  return buildServer({
    logger: createSilentLogger(),
    metrics: new Metrics({ defaultMetrics: false }),
    enqueuer: new MemoryEnqueuer(),
    webhookEvents: new MemoryWebhookEventStore(),
    product: PRODUCT,
    stripeWebhookSecret: 'whsec_test_local',
    telegramWebhook: { enabled: false, secret: undefined },
    devBot: bot,
    dashboard: null,
    readiness: {},
  });
}

export async function sendDev(server: FastifyInstance, body: Record<string, unknown>): Promise<OutboundCall[]> {
  const response = await server.inject({ method: 'POST', url: '/dev/telegram/update', payload: body });
  if (response.statusCode !== 200) throw new Error(`dev route ${response.statusCode}: ${response.body}`);
  return (response.json<{ outbound: OutboundCall[] }>()).outbound;
}

export function sentMessages(outbound: OutboundCall[]): Array<{ text: string; reply_markup?: { inline_keyboard: Array<Array<Record<string, string>>> } }> {
  return outbound
    .filter((call) => call.method === 'sendMessage')
    .map((call) => call.payload as { text: string; reply_markup?: { inline_keyboard: Array<Array<Record<string, string>>> } });
}
