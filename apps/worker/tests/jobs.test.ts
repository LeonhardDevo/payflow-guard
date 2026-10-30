import { MemoryChatPreferences } from '../src/db/telegramChats.js';
import { readFileSync } from 'node:fs';
import { UnrecoverableError } from 'bullmq';
import { GrammyError } from 'grammy';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { MemoryActionStore, MemoryCache } from '../src/bot/stores.js';
import { createRegistry } from '../src/bot/registry.js';
import { coreModule } from '../src/bot/modules/core.js';
import type { BotModule } from '../src/bot/types.js';
import { UpstreamHttpError, UpstreamNetworkError } from '../src/clients/errors.js';
import { MemoryWebhookEventStore } from '../src/db/webhookEvents.js';
import { Translator } from '../src/i18n/index.js';
import type { JobDeps, TelegramSender } from '../src/jobs/deps.js';
import { emailPayloadSchema } from '../src/jobs/email.js';
import { createHandlers } from '../src/jobs/index.js';
import type { JobContext } from '../src/jobs/types.js';
import { createSilentLogger } from '../src/logger.js';
import type { Mailer } from '../src/mail/mailer.js';
import { fakeAi, fakeWeb, linkedContext } from './helpers/fakes.js';

const OUTBOX_ID = '8f9a4a0e-2a54-4e8e-bb0b-0d3c2f1e9a11';

function ctx(overrides: Partial<JobContext> = {}): JobContext {
  return {
    queue: 'q',
    name: 'n',
    jobId: OUTBOX_ID,
    attempt: 1,
    maxAttempts: 5,
    isFinalAttempt: false,
    requestId: 'req-1',
    logger: createSilentLogger(),
    ...overrides,
  };
}

function envelope(topic: string, payload: unknown, workspaceId: number | null = 3) {
  return { outbox_id: OUTBOX_ID, topic, workspace_id: workspaceId, payload };
}

const invoicesModule: BotModule = {
  name: 'invoices',
  notifications: {
    'invoice.overdue': (params, { t, escape, locale }) => ({
      html: `<b>${escape(String(params.number))}</b> ${locale === 'ru' ? 'просрочен' : 'is overdue'} — ${t('notify.fallback')}`,
      buttons: [{ text: 'Approve', callback: `invoice.approve:${String(params.id)}` }],
    }),
  },
};

let web: ReturnType<typeof fakeWeb>;
let ai: ReturnType<typeof fakeAi>;
let mailer: { send: ReturnType<typeof vi.fn<Mailer['send']>>; verify: ReturnType<typeof vi.fn<Mailer['verify']>>; close: ReturnType<typeof vi.fn<Mailer['close']>> };
let telegram: { sendMessage: ReturnType<typeof vi.fn<TelegramSender['sendMessage']>> };
let markers: MemoryCache;
let events: MemoryWebhookEventStore;
let actions: MemoryActionStore;
let bot: { handleUpdate: ReturnType<typeof vi.fn<JobDeps['bot']['handleUpdate']>> };
let handlers: ReturnType<typeof createHandlers>;

beforeEach(() => {
  web = fakeWeb(new Map([[5001, linkedContext()]]));
  ai = fakeAi();
  mailer = { send: vi.fn<Mailer['send']>(async () => ({ messageId: '<smtp-1@mailpit>' })), verify: vi.fn<Mailer['verify']>(async () => true), close: vi.fn<Mailer['close']>() };
  telegram = { sendMessage: vi.fn<TelegramSender['sendMessage']>(async () => ({ message_id: 321 })) };
  markers = new MemoryCache();
  events = new MemoryWebhookEventStore();
  actions = new MemoryActionStore();
  bot = { handleUpdate: vi.fn<JobDeps['bot']['handleUpdate']>(async () => undefined) };
  const translator = new Translator('Acme Desk');
  const deps: JobDeps = {
    web,
    ai,
    mailer,
    telegram,
    sentMarkers: markers,
    webhookEvents: events,
    telegramChats: new MemoryChatPreferences(),
    bot,
    registry: createRegistry([coreModule, invoicesModule], translator),
    translator,
    actions,
    settings: { productSlug: 'acme', mailFrom: '"Acme Desk" <no-reply@acme.test>', notificationActionTtlS: 3600 },
  };
  handlers = createHandlers(deps);
});

describe('email.send', () => {
  const payload = {
    to: 'anna@example.com',
    subject: 'Счёт оплачен',
    html: '<p>Готово</p>',
    text: 'Готово',
    locale: 'ru',
    reference: { type: 'invoice', id: 42 },
    workspace_id: 3,
  };
  const run = (data: unknown, context = ctx()) => handlers.emails['email.send']!(data, context);

  it('sends via SMTP with a deterministic Message-ID, then reports the delivery', async () => {
    await run(envelope('email.send', payload));
    expect(mailer.send).toHaveBeenCalledWith(
      expect.objectContaining({
        from: '"Acme Desk" <no-reply@acme.test>',
        to: 'anna@example.com',
        subject: 'Счёт оплачен',
        messageId: `<${OUTBOX_ID}@acme.worker>`,
        headers: expect.objectContaining({ 'X-Reference': 'invoice:42', 'Content-Language': 'ru' }) as unknown,
      }),
    );
    expect(web.reportDelivery).toHaveBeenCalledWith(
      { reference: { type: 'invoice', id: 42 }, channel: 'email', status: 'sent', provider_message_id: '<smtp-1@mailpit>' },
      'req-1',
    );
  });

  it('never re-sends on retry after the callback failed', async () => {
    web.reportDelivery.mockRejectedValueOnce(new UpstreamNetworkError('web', 'POST', '/internal/v1/deliveries', new Error('down')));
    await expect(run(envelope('email.send', payload))).rejects.toBeInstanceOf(UpstreamNetworkError);
    await run(envelope('email.send', payload), ctx({ attempt: 2 }));
    expect(mailer.send).toHaveBeenCalledTimes(1);
    expect(web.reportDelivery).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'sent', provider_message_id: '<smtp-1@mailpit>' }), 'req-1');
  });

  it('fails permanently on SMTP 5xx and reports the failure', async () => {
    mailer.send.mockRejectedValueOnce(Object.assign(new Error('550 mailbox unavailable'), { responseCode: 550 }));
    await expect(run(envelope('email.send', payload))).rejects.toBeInstanceOf(UnrecoverableError);
    expect(web.reportDelivery).toHaveBeenCalledWith(expect.objectContaining({ status: 'failed', channel: 'email' }), 'req-1');
  });

  it('retries transient SMTP errors and reports failure only on the last attempt', async () => {
    mailer.send.mockRejectedValue(Object.assign(new Error('connect ECONNREFUSED'), { code: 'ECONNECTION' }));
    await expect(run(envelope('email.send', payload))).rejects.toThrow('ECONNREFUSED');
    expect(web.reportDelivery).not.toHaveBeenCalled();
    await expect(run(envelope('email.send', payload), ctx({ attempt: 5, isFinalAttempt: true }))).rejects.toThrow('ECONNREFUSED');
    expect(web.reportDelivery).toHaveBeenCalledWith(expect.objectContaining({ status: 'failed' }), 'req-1');
  });

  describe('shared contract samples (contracts/outbox/email-send.v1.samples.json, also asserted by apps/web OutboxTest)', () => {
    const fixture = JSON.parse(readFileSync(new URL('../../../contracts/outbox/email-send.v1.samples.json', import.meta.url), 'utf8')) as {
      topic: string;
      valid: Array<{ name: string; payload: Record<string, unknown> }>;
      invalid: Array<{ name: string; payload: Record<string, unknown> }>;
    };

    it('covers the documented cases', () => {
      expect(fixture.topic).toBe('email.send');
      expect(fixture.valid.map((c) => c.name)).toEqual(['html_only_no_reply_to', 'text_only_with_extras']);
      expect(fixture.invalid.length).toBeGreaterThan(0);
    });

    it.each(fixture.valid.map((c) => [c.name, c.payload] as const))('accepts %s as published by web', async (_name, webPayload) => {
      expect(emailPayloadSchema.safeParse(webPayload).success).toBe(true);
      await run(envelope('email.send', webPayload));
      expect(mailer.send).toHaveBeenCalledTimes(1);
      const sent = mailer.send.mock.calls[0]![0];
      const from = webPayload.from as { address: string; name: string };
      expect(sent.from).toEqual({ address: from.address, name: from.name });
      expect(sent.subject).toBe(webPayload.subject);
      expect(sent.headers).toMatchObject({ 'Content-Language': webPayload.locale });
      expect(web.reportDelivery).toHaveBeenCalledWith(expect.objectContaining({ status: 'sent', channel: 'email' }), 'req-1');
    });

    it.each(fixture.invalid.map((c) => [c.name, c.payload] as const))('rejects %s permanently without sending', async (_name, badPayload) => {
      expect(emailPayloadSchema.safeParse(badPayload).success).toBe(false);
      await expect(run(envelope('email.send', badPayload))).rejects.toBeInstanceOf(UnrecoverableError);
      expect(mailer.send).not.toHaveBeenCalled();
    });

    it('maps nulls, the recipient name, reply-to and attachments to SMTP', async () => {
      const [htmlOnly, extras] = fixture.valid;
      await run(envelope('email.send', htmlOnly!.payload));
      const first = mailer.send.mock.calls[0]![0];
      expect(first.to).toBe('new.user@example.com');
      expect(first.html).toContain('verify your email');
      expect(first).not.toHaveProperty('text');
      expect(first).not.toHaveProperty('replyTo');
      expect(first).not.toHaveProperty('attachments');

      mailer.send.mockClear();
      await markers.del(`sent:email:${OUTBOX_ID}`);
      await run(envelope('email.send', extras!.payload));
      const second = mailer.send.mock.calls[0]![0];
      expect(second.to).toEqual({ address: 'anna@example.com', name: 'Анна Петрова' });
      expect(second.replyTo).toEqual({ address: 'owner@acme.test', name: '' });
      expect(second.text).toBe('Plain only');
      expect(second).not.toHaveProperty('html');
      expect(second.attachments?.[0]).toMatchObject({ filename: 'report.txt', contentType: 'text/plain' });
      expect(second.attachments?.[0]?.content.toString()).toBe('hello');
    });
  });

  it('falls back to MAIL_FROM and accepts a legacy string reply_to', async () => {
    await run(envelope('email.send', { ...payload, reply_to: 'boss@acme.test' }));
    expect(mailer.send).toHaveBeenCalledWith(expect.objectContaining({ from: '"Acme Desk" <no-reply@acme.test>', replyTo: 'boss@acme.test' }));
  });

  it('rejects invalid payloads without retrying', async () => {
    await expect(run(envelope('email.send', { ...payload, to: 'nobody' }))).rejects.toBeInstanceOf(UnrecoverableError);
    await expect(run(envelope('email.send', { ...payload, html: undefined, text: undefined }))).rejects.toBeInstanceOf(UnrecoverableError);
    await expect(run({ nope: true })).rejects.toBeInstanceOf(UnrecoverableError);
    expect(mailer.send).not.toHaveBeenCalled();
  });
});

describe('telegram.notify', () => {
  const run = (data: unknown, context = ctx()) => handlers.telegram['telegram.notify']!(data, context);

  it('sends sanitized HTML with buttons to an explicit chat_id', async () => {
    await run(
      envelope('telegram.notify', {
        chat_id: 777,
        text_html: '<p>Invoice <strong>A-1</strong> paid</p>',
        buttons: [{ text: 'Open', url: 'https://app.acme.example/invoices/1' }, { text: 'Approve', callback: 'invoice.approve:1' }],
        reference: { type: 'invoice', id: 1 },
      }),
    );
    const [chatId, html, markup] = telegram.sendMessage.mock.calls[0]!;
    expect(chatId).toBe(777);
    expect(html).toBe('Invoice <b>A-1</b> paid');
    const rows = markup!.inline_keyboard;
    expect(rows[0]![0]).toMatchObject({ text: 'Open', url: 'https://app.acme.example/invoices/1' });
    const callback = (rows[1]![0] as { callback_data: string }).callback_data;
    expect(await actions.get(callback.slice(2))).toMatchObject({ type: 'invoice.approve', payload: { value: 'invoice.approve:1', arg: '1' }, chat_id: 777 });
    expect(web.reportDelivery).toHaveBeenCalledWith(
      { reference: { type: 'invoice', id: 1 }, channel: 'telegram', status: 'sent', provider_message_id: '321' },
      'req-1',
    );
  });

  it('resolves the chat for an app user via web context and renders templates in their locale', async () => {
    await run(
      envelope('telegram.notify', { user_id: 7, template: 'invoice.overdue', params: { number: 'A-7', id: 7 }, reference: { type: 'invoice', id: 7 } }),
    );
    expect(web.telegramContext).toHaveBeenCalledWith({ userId: 7 }, 'req-1');
    const [chatId, html, markup] = telegram.sendMessage.mock.calls[0]!;
    expect(chatId).toBe(5001);
    expect(html).toBe('<b>A-7</b> просрочен — У вас новое уведомление в Acme Desk.');
    const callback = (markup!.inline_keyboard[0]![0] as { callback_data: string }).callback_data;
    expect(await actions.get(callback.slice(2))).toMatchObject({ telegram_user_id: 5001, user_id: 7, locale: 'ru' });
  });

  it('reports not-linked users as failed deliveries without retrying', async () => {
    const result = await run(envelope('telegram.notify', { user_id: 999, text_html: 'x', reference: { type: 't', id: 1 } }));
    expect(result).toEqual({ skipped: 'telegram_not_linked' });
    expect(telegram.sendMessage).not.toHaveBeenCalled();
    expect(web.reportDelivery).toHaveBeenCalledWith(expect.objectContaining({ status: 'failed', error: 'telegram_not_linked' }), 'req-1');
  });

  it('treats a blocked bot as permanent and a 429 as retryable', async () => {
    telegram.sendMessage.mockRejectedValueOnce(new GrammyError('Forbidden', { ok: false, error_code: 403, description: 'Forbidden: bot was blocked by the user' }, 'sendMessage', {}));
    await expect(run(envelope('telegram.notify', { chat_id: 1, text_html: 'x', reference: { type: 't', id: 1 } }))).rejects.toBeInstanceOf(
      UnrecoverableError,
    );
    expect(web.reportDelivery).toHaveBeenCalledWith(expect.objectContaining({ status: 'failed' }), 'req-1');

    web.reportDelivery.mockClear();
    telegram.sendMessage.mockRejectedValueOnce(new GrammyError('Too Many Requests', { ok: false, error_code: 429, description: 'Too Many Requests: retry after 3' }, 'sendMessage', {}));
    await expect(run(envelope('telegram.notify', { chat_id: 1, text_html: 'x', reference: { type: 't', id: 1 } }))).rejects.toBeInstanceOf(GrammyError);
    expect(web.reportDelivery).not.toHaveBeenCalled();
  });

  it('is idempotent across retries', async () => {
    web.reportDelivery.mockRejectedValueOnce(new UpstreamHttpError('web', 'POST', '/internal/v1/deliveries', 502, null));
    const data = envelope('telegram.notify', { chat_id: 1, text_html: 'hi', reference: { type: 't', id: 1 } });
    await expect(run(data)).rejects.toBeInstanceOf(UpstreamHttpError);
    await run(data, ctx({ attempt: 2 }));
    expect(telegram.sendMessage).toHaveBeenCalledTimes(1);
  });

  it('rejects unknown templates and payloads without a recipient', async () => {
    await expect(run(envelope('telegram.notify', { chat_id: 1, template: 'nope', reference: { type: 't', id: 1 } }))).rejects.toBeInstanceOf(
      UnrecoverableError,
    );
    await expect(run(envelope('telegram.notify', { text_html: 'x', reference: { type: 't', id: 1 } }))).rejects.toBeInstanceOf(UnrecoverableError);
  });
});

describe('documents', () => {
  it('asks ai to ingest uploaded documents with metadata', async () => {
    await handlers.documents['document.uploaded']!(
      envelope('document.uploaded', { document_id: 11, workspace_id: 3, title: 'Policy', mime: 'application/pdf', metadata: { roles: ['admin'] } }),
      ctx(),
    );
    expect(ai.ingestDocument).toHaveBeenCalledWith(11, { workspace_id: 3, ai_external_allowed: false, title: 'Policy', mime: 'application/pdf', metadata: { roles: ['admin'] } }, 'req-1');
  });

  it('marks the document failed in web when giving up', async () => {
    ai.ingestDocument.mockRejectedValue(new UpstreamNetworkError('ai', 'POST', '/v1/documents/11/ingest', new Error('down')));
    const data = envelope('document.uploaded', { document_id: 11, workspace_id: 3 });
    await expect(handlers.documents['document.uploaded']!(data, ctx())).rejects.toBeInstanceOf(UpstreamNetworkError);
    expect(web.reportDocumentStatus).not.toHaveBeenCalled();
    await expect(handlers.documents['document.uploaded']!(data, ctx({ isFinalAttempt: true }))).rejects.toBeInstanceOf(UpstreamNetworkError);
    expect(web.reportDocumentStatus).toHaveBeenCalledWith(11, { status: 'failed', error: expect.stringContaining('down') as unknown }, 'req-1');
  });

  it('stops retrying on 4xx from ai', async () => {
    ai.ingestDocument.mockRejectedValueOnce(new UpstreamHttpError('ai', 'POST', '/v1/documents/11/ingest', 422, { message: 'unsupported mime' }));
    await expect(handlers.documents['document.uploaded']!(envelope('document.uploaded', { document_id: 11, workspace_id: 3 }), ctx())).rejects.toBeInstanceOf(
      UnrecoverableError,
    );
    expect(web.reportDocumentStatus).toHaveBeenCalledWith(11, expect.objectContaining({ status: 'failed' }), 'req-1');
  });

  it('deletes chunks and re-embeds workspaces', async () => {
    await handlers.documents['document.deleted']!(envelope('document.deleted', { document_id: 11, workspace_id: 3 }), ctx());
    await handlers.documents['workspace.reembed']!(envelope('workspace.reembed', { workspace_id: 3 }), ctx());
    expect(ai.deleteDocument).toHaveBeenCalledWith(11, 3, 'req-1');
    expect(ai.reembedWorkspace).toHaveBeenCalledWith(3, false, 'req-1');
  });
});

describe('stripe-events', () => {
  const event = { id: 'evt_1', object: 'event', type: 'customer.subscription.updated', data: { object: {} } };

  it('forwards to web and marks the event processed', async () => {
    await events.record({ provider: 'stripe', eventId: 'evt_1', type: event.type, payload: event });
    await handlers['stripe-events']['stripe.event']!(event, ctx());
    expect(web.forwardStripeEvent).toHaveBeenCalledWith(event, 'req-1');
    expect(events.rows.get('stripe:evt_1')).toMatchObject({ processed: true });
  });

  it('records failures and retries transient errors', async () => {
    await events.record({ provider: 'stripe', eventId: 'evt_1', type: event.type, payload: event });
    web.forwardStripeEvent.mockRejectedValueOnce(new UpstreamHttpError('web', 'POST', '/internal/v1/stripe/events', 503, null));
    await expect(handlers['stripe-events']['stripe.event']!(event, ctx())).rejects.toBeInstanceOf(UpstreamHttpError);
    expect(events.rows.get('stripe:evt_1')).toMatchObject({ processed: false, attempts: 1 });
    expect(events.rows.get('stripe:evt_1')?.lastError).toContain('503');
  });

  it('rejects malformed events permanently', async () => {
    await expect(handlers['stripe-events']['stripe.event']!({ type: 'x' }, ctx())).rejects.toBeInstanceOf(UnrecoverableError);
  });
});

describe('billing queue', () => {
  const notification = { type: 'notification', event: 'payment.succeeded', object: { id: 'pay_1', status: 'succeeded' } };

  it('forwards YooKassa notifications and marks them processed', async () => {
    await events.record({ provider: 'yookassa', eventId: 'payment.succeeded:pay_1', type: 'payment.succeeded', payload: notification });
    await handlers.billing['yookassa.notification']!(notification, ctx());
    expect(web.forwardYooKassaNotification).toHaveBeenCalledWith(notification, 'req-1');
    expect(events.rows.get('yookassa:payment.succeeded:pay_1')).toMatchObject({ processed: true });
  });

  it('records a failed forward so the redelivery can retry it', async () => {
    await events.record({ provider: 'yookassa', eventId: 'payment.succeeded:pay_1', type: 'payment.succeeded', payload: notification });
    web.forwardYooKassaNotification.mockRejectedValueOnce(new UpstreamHttpError('web', 'POST', '/internal/v1/yookassa/notifications', 502, null));
    await expect(handlers.billing['yookassa.notification']!(notification, ctx())).rejects.toBeInstanceOf(UpstreamHttpError);
    expect(events.rows.get('yookassa:payment.succeeded:pay_1')).toMatchObject({ processed: false, attempts: 1 });
  });

  it('forwards verified per-account Stripe events', async () => {
    const event = { id: 'evt_acct', object: 'event', type: 'checkout.session.completed', data: { object: {} } };
    await events.record({ provider: 'stripe-account-7', eventId: 'evt_acct', type: event.type, payload: event });
    await handlers.billing['stripe.account.event']!({ accountId: 7, event }, ctx());
    expect(web.forwardStripeAccountEvent).toHaveBeenCalledWith(7, event, 'req-1');
    expect(events.rows.get('stripe-account-7:evt_acct')).toMatchObject({ processed: true });
    await expect(handlers.billing['stripe.account.event']!({ accountId: 'x', event }, ctx())).rejects.toBeInstanceOf(UnrecoverableError);
  });

  it('runs scheduled renewals and payment reconciliation through web', async () => {
    await handlers.billing['billing.renewals.due']!({ scheduled: true }, ctx());
    await handlers.billing['payments.reconcile.due']!({ scheduled: true }, ctx());
    expect(web.runBillingRenewals).toHaveBeenCalledWith('req-1');
    expect(web.reconcilePayments).toHaveBeenCalledWith('req-1');
  });
});

describe('payment-events queue', () => {
  const event = { id: 'evt_pay_1', object: 'event', type: 'checkout.session.completed', data: { object: { payment_link: 'plink_1' } } };
  const job = { driver: 'fake', payment_account_id: 12, workspace_id: 4, event };

  it('forwards the event with the endpoint account and marks it processed', async () => {
    await events.record({ provider: 'payment-fake', eventId: 'evt_pay_1', type: event.type, payload: {} });
    web.forwardPaymentEvent.mockResolvedValueOnce({ result: 'duplicate' });
    const result = await handlers['payment-events']['payment.event']!(job, ctx());
    expect(web.forwardPaymentEvent).toHaveBeenCalledWith(job, 'req-1');
    expect(result).toEqual({ forwarded: 'evt_pay_1', result: 'duplicate' });
    expect(events.rows.get('payment-fake:evt_pay_1')).toMatchObject({ processed: true });
  });

  it('keeps the event unprocessed when web is unavailable and stops on a rejected payload', async () => {
    await events.record({ provider: 'payment-stripe', eventId: 'evt_pay_1', type: event.type, payload: {} });
    web.forwardPaymentEvent.mockRejectedValueOnce(new UpstreamHttpError('web', 'POST', '/internal/v1/payments/events', 503, null));
    await expect(handlers['payment-events']['payment.event']!({ ...job, driver: 'stripe' }, ctx())).rejects.toBeInstanceOf(UpstreamHttpError);
    expect(events.rows.get('payment-stripe:evt_pay_1')).toMatchObject({ processed: false, attempts: 1 });

    web.forwardPaymentEvent.mockRejectedValueOnce(new UpstreamHttpError('web', 'POST', '/internal/v1/payments/events', 422, null));
    await expect(handlers['payment-events']['payment.event']!({ ...job, driver: 'stripe' }, ctx())).rejects.toBeInstanceOf(UnrecoverableError);
    await expect(handlers['payment-events']['payment.event']!({ ...job, driver: 'paypal' }, ctx())).rejects.toBeInstanceOf(UnrecoverableError);
  });
});

describe('telegram-updates', () => {
  it('passes updates to the bot', async () => {
    const update = { update_id: 5, message: { message_id: 1, date: 1, chat: { id: 1, type: 'private' }, text: 'hi' } };
    await handlers['telegram-updates']['telegram.update']!(update, ctx());
    expect(bot.handleUpdate).toHaveBeenCalledWith(update);
    await expect(handlers['telegram-updates']['telegram.update']!({}, ctx())).rejects.toBeInstanceOf(UnrecoverableError);
  });
});
