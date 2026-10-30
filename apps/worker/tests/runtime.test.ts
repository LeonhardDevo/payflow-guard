import { createServer, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';
import { setTimeout as sleep } from 'node:timers/promises';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { AccountResolver } from '../src/bot/accounts.js';
import { createBot } from '../src/bot/createBot.js';
import { defaultModules } from '../src/bot/modules/index.js';
import { createRegistry } from '../src/bot/registry.js';
import { TelegramRuntime } from '../src/bot/runtime.js';
import { MemoryActionStore, MemoryCache, MemoryRateLimiter } from '../src/bot/stores.js';
import type { BotServices } from '../src/bot/types.js';
import { loadConfig } from '../src/config.js';
import { MemoryChatPreferences } from '../src/db/telegramChats.js';
import { Translator } from '../src/i18n/index.js';
import { createSilentLogger } from '../src/logger.js';
import { MemoryEnqueuer } from '../src/queues/enqueuer.js';
import { fakeAi, fakeWeb } from './helpers/fakes.js';

class FakeBotApi {
  readonly calls: Array<{ method: string; body: Record<string, unknown> }> = [];
  readonly batches: unknown[][] = [];
  private server!: Server;
  root = '';

  async start(): Promise<void> {
    this.server = createServer((req, res) => {
      const chunks: Buffer[] = [];
      req.on('data', (chunk: Buffer) => chunks.push(chunk));
      req.on('end', () => {
        const method = (req.url ?? '').split('/').pop() ?? '';
        const raw = Buffer.concat(chunks).toString('utf8');
        const body = raw ? (JSON.parse(raw) as Record<string, unknown>) : {};
        this.calls.push({ method, body });
        const reply = (result: unknown) => {
          res.writeHead(200, { 'content-type': 'application/json' });
          res.end(JSON.stringify({ ok: true, result }));
        };
        if (method === 'getMe') return reply({ id: 99, is_bot: true, first_name: 'Acme', username: 'acme_real_bot' });
        if (method === 'getUpdates') {
          const batch = this.batches.shift();
          if (batch) return reply(batch);
          setTimeout(() => reply([]), 50);
          return;
        }
        return reply(true);
      });
    });
    await new Promise<void>((resolve) => this.server.listen(0, '127.0.0.1', resolve));
    this.root = `http://127.0.0.1:${(this.server.address() as AddressInfo).port}`;
  }

  async stop(): Promise<void> {
    this.server.closeAllConnections();
    await new Promise<void>((resolve) => this.server.close(() => resolve()));
  }
}

const baseEnv = {
  PRODUCT_NAME: 'Acme Desk',
  PRODUCT_SLUG: 'acme',
  DATABASE_URL: 'postgres://app:app@127.0.0.1:5441/acme',
  REDIS_URL: 'redis://127.0.0.1:6391',
  WEB_URL: 'http://127.0.0.1:8100',
  AI_URL: 'http://127.0.0.1:8101',
  TELEGRAM_BOT_TOKEN: '123:TEST',
};

let api: FakeBotApi;

beforeEach(async () => {
  api = new FakeBotApi();
  await api.start();
});

afterEach(async () => {
  await api.stop();
});

function setup(mode: 'polling' | 'webhook') {
  const config = loadConfig({
    ...baseEnv,
    TELEGRAM_MODE: mode,
    TELEGRAM_API_ROOT: api.root,
    TELEGRAM_WEBHOOK_SECRET: 'hook-secret',
    TELEGRAM_WEBHOOK_URL: 'https://bot.acme.example/webhooks/telegram',
  });
  const translator = new Translator('Acme Desk');
  const registry = createRegistry(defaultModules(), translator);
  const web = fakeWeb();
  const services: BotServices = {
    settings: { productName: 'Acme Desk', botUsername: 'acme_bot', webPublicUrl: 'http://127.0.0.1:8100', rateLimit: 20, rateWindowS: 60, actionTtlS: 60, logAiQueries: false },
    translator,
    web,
    ai: fakeAi(),
    actions: new MemoryActionStore(),
    rateLimiter: new MemoryRateLimiter(),
    preferences: new MemoryChatPreferences(),
    accounts: new AccountResolver(web, new MemoryCache(), 60),
    logger: createSilentLogger(),
    registry,
  };
  const handle = createBot(services, { token: config.telegram.token, apiRoot: config.telegram.apiRoot, botUsername: 'acme_bot', productName: 'Acme Desk' });
  const enqueuer = new MemoryEnqueuer();
  const runtime = new TelegramRuntime(handle, config.telegram, enqueuer, registry, translator, createSilentLogger());
  return { runtime, enqueuer, handle };
}

describe('TelegramRuntime', () => {
  it('long-polls getUpdates and enqueues every update, advancing the offset', async () => {
    api.batches.push([
      { update_id: 500, message: { message_id: 1, date: 1, chat: { id: 1, type: 'private' }, text: 'a' } },
      { update_id: 501, message: { message_id: 2, date: 1, chat: { id: 1, type: 'private' }, text: 'b' } },
    ]);
    const { runtime, enqueuer, handle } = setup('polling');
    await runtime.start();
    try {
      const deadline = Date.now() + 3000;
      while (enqueuer.jobs.length < 2 && Date.now() < deadline) await sleep(20);
      while (!api.calls.some((call) => call.method === 'getUpdates' && call.body.offset === 502) && Date.now() < deadline) await sleep(20);
    } finally {
      await runtime.stop();
    }
    expect(runtime.mode).toBe('polling');
    expect(handle.bot.botInfo.username).toBe('acme_real_bot');
    expect(enqueuer.jobs.map((job) => [job.queue, job.jobId])).toEqual([
      ['telegram-updates', 'tg-update-500'],
      ['telegram-updates', 'tg-update-501'],
    ]);
    const methods = api.calls.map((call) => call.method);
    expect(methods).toContain('deleteWebhook');
    const commandCalls = api.calls.filter((call) => call.method === 'setMyCommands');
    expect(commandCalls).toHaveLength(2);
    expect(commandCalls[1]?.body).toMatchObject({ language_code: 'ru', commands: expect.arrayContaining([{ command: 'lang', description: 'Сменить язык' }]) as unknown });
    expect(api.calls.find((call) => call.method === 'getUpdates' && call.body.offset === 502)).toBeTruthy();
  });

  it('registers the webhook with the secret token in webhook mode', async () => {
    const { runtime } = setup('webhook');
    await runtime.start();
    await runtime.stop();
    const setWebhook = api.calls.find((call) => call.method === 'setWebhook');
    expect(setWebhook?.body).toMatchObject({
      url: 'https://bot.acme.example/webhooks/telegram',
      secret_token: 'hook-secret',
      allowed_updates: ['message', 'callback_query'],
    });
    expect(api.calls.some((call) => call.method === 'getUpdates')).toBe(false);
  });
});
