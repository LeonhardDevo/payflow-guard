import type { FastifyInstance } from 'fastify';
import { UnrecoverableError } from 'bullmq';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { MemoryActionStore, MemoryCache } from '../src/bot/stores.js';
import { createRegistry } from '../src/bot/registry.js';
import { coreModule } from '../src/bot/modules/core.js';
import { telegramContextSchema } from '../src/clients/web.js';
import { UpstreamHttpError } from '../src/clients/errors.js';
import { MemoryChatPreferences } from '../src/db/telegramChats.js';
import { MemoryWebhookEventStore } from '../src/db/webhookEvents.js';
import { Translator } from '../src/i18n/index.js';
import type { JobDeps } from '../src/jobs/deps.js';
import { createHandlers } from '../src/jobs/index.js';
import type { JobContext } from '../src/jobs/types.js';
import { createSilentLogger } from '../src/logger.js';
import type { Mailer } from '../src/mail/mailer.js';
import { PRIVACY_PURGE_SCHEDULER_ID, schedulePrivacyPurge, type SchedulerQueue } from '../src/queues/scheduler.js';
import { resolveQueue } from '../src/queues/routes.js';
import { createTestBot, devServer, sendDev, sentMessages, type TestBot } from './helpers/bot.js';
import { fakeAi, fakeWeb, linkedContext } from './helpers/fakes.js';

const OUTBOX_ID = '0d6f3f8e-0000-4000-8000-000000000042';

function ctx(overrides: Partial<JobContext> = {}): JobContext {
  return {
    queue: 'privacy',
    name: 'n',
    jobId: 'job-1',
    attempt: 1,
    maxAttempts: 5,
    isFinalAttempt: false,
    requestId: 'req-1',
    logger: createSilentLogger(),
    ...overrides,
  };
}

const envelope = (topic: string, payload: unknown, workspaceId: number | null = 3) => ({ outbox_id: OUTBOX_ID, topic, workspace_id: workspaceId, payload });

describe('privacy jobs', () => {
  let web: ReturnType<typeof fakeWeb>;
  let ai: ReturnType<typeof fakeAi>;
  let events: MemoryWebhookEventStore;
  let mailer: { send: ReturnType<typeof vi.fn<Mailer['send']>>; verify: ReturnType<typeof vi.fn<Mailer['verify']>>; close: ReturnType<typeof vi.fn<Mailer['close']>> };
  let handlers: ReturnType<typeof createHandlers>;
  let chats: MemoryChatPreferences;

  beforeEach(() => {
    web = fakeWeb(new Map([[5001, linkedContext()]]));
    ai = fakeAi();
    events = new MemoryWebhookEventStore();
    chats = new MemoryChatPreferences();
    vi.spyOn(chats, 'forget');
    mailer = { send: vi.fn<Mailer['send']>(async () => ({ messageId: '<m-1>' })), verify: vi.fn<Mailer['verify']>(async () => true), close: vi.fn<Mailer['close']>() };
    const translator = new Translator('Acme Desk');
    const deps: JobDeps = {
      web,
      ai,
      mailer,
      telegram: { sendMessage: vi.fn(async () => ({ message_id: 1 })) },
      sentMarkers: new MemoryCache(),
      webhookEvents: events,
      telegramChats: chats,
      bot: { handleUpdate: vi.fn(async () => undefined) },
      registry: createRegistry([coreModule], translator),
      translator,
      actions: new MemoryActionStore(),
      settings: { productSlug: 'acme', mailFrom: 'no-reply@acme.test', notificationActionTtlS: 60 },
    };
    handlers = createHandlers(deps);
  });

  it('routes the privacy topics', () => {
    expect(resolveQueue('privacy.export.requested')).toBe('privacy');
    expect(resolveQueue('privacy.purge.due')).toBe('privacy');
    expect(resolveQueue('workspace.purge')).toBe('documents');
  });

  it('asks web to build a requested export', async () => {
    await handlers.privacy['privacy.export.requested']!(envelope('privacy.export.requested', { export_id: 9 }, null), ctx());
    expect(web.buildExport).toHaveBeenCalledWith(9, 'req-1');
  });

  it('retries a failed build and rejects malformed payloads permanently', async () => {
    web.buildExport.mockRejectedValueOnce(new UpstreamHttpError('web', 'POST', '/internal/v1/privacy/exports/9/build', 500, null));
    await expect(handlers.privacy['privacy.export.requested']!(envelope('privacy.export.requested', { export_id: 9 }), ctx())).rejects.toBeInstanceOf(
      UpstreamHttpError,
    );
    await expect(handlers.privacy['privacy.export.requested']!(envelope('privacy.export.requested', { export_id: 'x' }), ctx())).rejects.toBeInstanceOf(
      UnrecoverableError,
    );
  });

  it('runs the daily purge in web, then purges old webhook events with the returned retention', async () => {
    await events.record({ provider: 'stripe', eventId: 'old', type: 't', payload: {} });
    await events.record({ provider: 'stripe', eventId: 'new', type: 't', payload: {} });
    events.rows.get('stripe:old')!.receivedAt = Date.now() - 31 * 86_400_000;

    const result = await handlers.privacy['privacy.purge.due']!({ scheduled: true }, ctx());

    expect(web.privacyPurge).toHaveBeenCalledWith('req-1');
    expect(result).toMatchObject({ retention: { ai_queries: 4, webhook_events: 1 }, deletions: { users: 1 } });
    expect([...events.rows.keys()]).toEqual(['stripe:new']);
  });

  it('does nothing else when another purge holds the lock', async () => {
    web.privacyPurge.mockResolvedValueOnce({ deletions: {}, retention: {}, webhook_events_days: 30, skipped: true });
    const purge = vi.spyOn(events, 'purgeOlderThan');
    expect(await handlers.privacy['privacy.purge.due']!(envelope('privacy.purge.due', {}), ctx())).toEqual({ skipped: true });
    expect(purge).not.toHaveBeenCalled();
  });

  it('erases a purged user in ai and forgets the linked telegram chats', async () => {
    expect(resolveQueue('user.purge')).toBe('privacy');
    const result = await handlers.privacy['user.purge']!(envelope('user.purge', { user_id: 7, telegram_user_ids: [5001] }, null), ctx());
    expect(ai.purgeUser).toHaveBeenCalledWith(7, 'req-1');
    expect(chats.forget).toHaveBeenCalledWith([5001]);
    expect(result).toMatchObject({ purged: true });
  });

  it('prunes ai conversation memory with the retention web returns', async () => {
    const retention = { default_days: 180, workspaces: [{ workspace_id: 3, days: 7 }] };
    web.privacyPurge.mockResolvedValueOnce({ deletions: {}, retention: {}, webhook_events_days: 30, ai_memory: retention, skipped: false });
    const result = await handlers.privacy['privacy.purge.due']!({ scheduled: true }, ctx());
    expect(ai.pruneMemory).toHaveBeenCalledWith(retention, 'req-1');
    expect(result).toMatchObject({ ai_memory: 'pruned' });
  });

  it('purges a deleted workspace in ai', async () => {
    await handlers.documents['workspace.purge']!(envelope('workspace.purge', { workspace_id: 3 }), ctx());
    expect(ai.purgeWorkspace).toHaveBeenCalledWith(3, 'req-1');
  });

  it('passes only whitelisted single-line extra headers to SMTP', async () => {
    const payload = {
      to: 'client@example.com',
      subject: 'Reminder',
      html: '<p>Hi</p>',
      reference: { type: 'mail', id: 1 },
      headers: {
        'List-Unsubscribe': '<https://app.test/email/opt-out/abc.def>',
        'List-Unsubscribe-Post': 'List-Unsubscribe=One-Click',
        'X-Evil': 'nope',
      },
    };
    await handlers.emails['email.send']!(envelope('email.send', payload), ctx({ queue: 'emails' }));
    const headers = mailer.send.mock.calls[0]![0].headers!;
    expect(headers['List-Unsubscribe']).toBe('<https://app.test/email/opt-out/abc.def>');
    expect(headers['List-Unsubscribe-Post']).toBe('List-Unsubscribe=One-Click');
    expect(headers).not.toHaveProperty('X-Evil');

    const injected = { ...payload, headers: { 'List-Unsubscribe': '<x>\r\nBcc: victim@example.com' } };
    await expect(handlers.emails['email.send']!(envelope('email.send', injected), ctx({ queue: 'emails' }))).rejects.toBeInstanceOf(UnrecoverableError);
  });
});

describe('privacy purge scheduler', () => {
  it('registers one UTC job scheduler for the daily purge', async () => {
    const queue = { upsertJobScheduler: vi.fn(async () => ({}) as never), removeJobScheduler: vi.fn(async () => true) } as unknown as SchedulerQueue & {
      upsertJobScheduler: ReturnType<typeof vi.fn>;
      removeJobScheduler: ReturnType<typeof vi.fn>;
    };
    await schedulePrivacyPurge(queue, '15 3 * * *', createSilentLogger());
    expect(queue.upsertJobScheduler).toHaveBeenCalledWith(
      PRIVACY_PURGE_SCHEDULER_ID,
      { pattern: '15 3 * * *', tz: 'UTC' },
      expect.objectContaining({ name: 'privacy.purge.due' }),
    );

    await schedulePrivacyPurge(queue, null, createSilentLogger());
    expect(queue.removeJobScheduler).toHaveBeenCalledWith(PRIVACY_PURGE_SCHEDULER_ID);
  });
});

describe('telegram context', () => {
  it('defaults the workspace AI data control to "not allowed"', () => {
    const parsed = telegramContextSchema.parse({ user: { id: 1, name: 'A', locale: 'en' }, workspace: { id: 2, name: 'W' } });
    expect(parsed.workspace?.ai_external_allowed).toBe(false);
  });
});

describe('bot privacy commands', () => {
  const ANNA = { id: 5001, username: 'anna', first_name: 'Anna', language_code: 'en' };
  let bot: TestBot;
  let server: FastifyInstance;

  async function setup(options: Parameters<typeof createTestBot>[0] = {}): Promise<void> {
    bot = createTestBot({ contexts: new Map([[ANNA.id, linkedContext({ user: { id: 7, name: 'Anna', locale: 'en' } })]]), ...options });
    server = await devServer(bot.handle);
  }

  afterEach(async () => {
    await server.close();
  });

  it('shows the plain-text first-contact notice once, then handles the message', async () => {
    await setup({ privacyNotice: true });
    const first = sentMessages(await sendDev(server, { from: ANNA, text: '/help' }));
    expect(first).toHaveLength(2);
    expect(first[0]!.text).toContain('Privacy notice from the Acme Desk bot');
    expect(first[0]!.text).toContain('http://127.0.0.1:8100/legal/privacy');
    expect(first[0]!.text).toContain('/unlink');
    expect(first[0]).not.toHaveProperty('parse_mode');
    expect(first[1]!.text).toContain('/privacy');

    const second = sentMessages(await sendDev(server, { from: ANNA, text: '/help' }));
    expect(second).toHaveLength(1);
  });

  it('explains what is stored on /privacy', async () => {
    await setup();
    const [reply] = sentMessages(await sendDev(server, { from: ANNA, text: '/privacy' }));
    expect(reply!.text).toContain('What we store');
    expect(reply!.text).toContain('/legal/privacy');
  });

  it('unlinks after confirmation on /unlink', async () => {
    await setup();
    const [prompt] = sentMessages(await sendDev(server, { from: ANNA, text: '/unlink' }));
    expect(prompt!.reply_markup?.inline_keyboard[0]?.map((button) => button.callback_data)).toEqual(['privacy:unlink', 'privacy:cancel']);

    const confirmed = await sendDev(server, { from: ANNA, callback_data: 'privacy:unlink' });
    expect(bot.web.unlinkTelegram).toHaveBeenCalledWith(ANNA.id, expect.any(String));
    const edit = confirmed.find((call) => call.method === 'editMessageText');
    expect((edit?.payload as { text: string }).text).toContain('no longer linked');
  });

  it('cancels /unlink without calling web', async () => {
    await setup();
    await sendDev(server, { from: ANNA, text: '/unlink' });
    const cancelled = await sendDev(server, { from: ANNA, callback_data: 'privacy:cancel' });
    expect(bot.web.unlinkTelegram).not.toHaveBeenCalled();
    expect((cancelled.find((call) => call.method === 'editMessageText')?.payload as { text: string }).text).toBe('Nothing changed.');
  });

  it('tells unlinked users there is nothing to unlink', async () => {
    await setup({ contexts: new Map() });
    const [reply] = sentMessages(await sendDev(server, { from: ANNA, text: '/unlink' }));
    expect(reply!.text).toContain('not linked');
  });

  it('remembers the notice per user in memory preferences', async () => {
    const preferences = new MemoryChatPreferences();
    expect(await preferences.markPrivacyNoticeShown(1)).toBe(true);
    expect(await preferences.markPrivacyNoticeShown(1)).toBe(false);
    expect(await new MemoryChatPreferences({ privacyNoticeSeen: true }).markPrivacyNoticeShown(2)).toBe(false);
  });
});
