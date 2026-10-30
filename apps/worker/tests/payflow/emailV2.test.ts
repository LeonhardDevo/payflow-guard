import { existsSync, readFileSync } from 'node:fs';
import { UnrecoverableError } from 'bullmq';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { MemoryActionStore, MemoryCache } from '../../src/bot/stores.js';
import { createRegistry } from '../../src/bot/registry.js';
import { MemoryChatPreferences } from '../../src/db/telegramChats.js';
import { MemoryWebhookEventStore } from '../../src/db/webhookEvents.js';
import { Translator } from '../../src/i18n/index.js';
import type { JobDeps } from '../../src/jobs/deps.js';
import { createEmailHandler, emailV2PayloadSchema, mailboxAddress } from '../../src/jobs/email.js';
import type { Mailer, OutgoingMail } from '../../src/mail/mailer.js';
import { CLIENT_MAIL_ATTEMPTS, CLIENT_MAIL_BACKOFF, clientMailDelayMs, customBackoff } from '../../src/queues/backoff.js';
import { jobOptionsFor } from '../../src/queues/routes.js';
import { fakeAi, fakeWeb } from '../helpers/fakes.js';
import { jobContext } from './fakes.js';

const OUTBOX_ID = '1f0c2b8e-5a51-4d1f-8f6c-2c3d4e5f6a7b';

const reminder = {
  v: 2,
  to: 'ap@helios-media.example',
  to_name: 'Rachel Moore',
  cc: ['cfo@helios-media.example'],
  from_name: 'Northstar Digital — Finance',
  reply_to: 'finance@northstar.example',
  subject: 'Final notice: invoice NS-2026-0118 ($12,400.00)',
  html: '<p>Dear Rachel Moore,</p>',
  text: 'Dear Rachel Moore,',
  locale: 'en',
  headers: {
    'List-Unsubscribe': '<http://localhost:8100/i/tok123/reminders/one-click>',
    'List-Unsubscribe-Post': 'List-Unsubscribe=One-Click',
    'X-PF-Reminder': '41',
  },
  attachments: [
    { filename: 'NS-2026-0118.pdf', content_type: 'application/pdf', internal_path: '/internal/v1/payflow/invoices/118/pdf?workspace_id=1' },
    { filename: 'note.txt', content_type: 'text/plain', content_base64: 'aGVsbG8=' },
  ],
  reference: { type: 'reminder', id: 41 },
  workspace_id: 1,
  retry_profile: 'client_mail',
};

const VALID = [
  reminder,
  { v: 2, to: 'anna@example.com', subject: 'Receipt', text: 'Thanks', reference: { type: 'payment_receipt', id: 9 } },
  { v: 2, to: 'anna@example.com', subject: 'Digest', html: '<p>Digest</p>', reply_to: null, cc: [], reference: { type: 'digest', id: '3' }, retry_profile: 'default' },
];

const INVALID: Array<[string, unknown]> = [
  ['header outside the allow-list', { ...reminder, headers: { 'Reply-To': 'x@example.com' } }],
  ['both attachment sources', { ...reminder, attachments: [{ filename: 'a.pdf', content_type: 'application/pdf', internal_path: '/internal/v1/outbox/attachments/7', content_base64: 'aGVsbG8=' }] }],
  ['attachment path outside web', { ...reminder, attachments: [{ filename: 'a.pdf', content_type: 'application/pdf', internal_path: 'http://evil.example/a.pdf' }] }],
  ['inline attachment over 256 KB', { ...reminder, attachments: [{ filename: 'big.bin', content_type: 'application/octet-stream', content_base64: Buffer.alloc(300 * 1024).toString('base64') }] }],
  ['more than five cc', { ...reminder, cc: ['a@x.io', 'b@x.io', 'c@x.io', 'd@x.io', 'e@x.io', 'f@x.io'] }],
  ['no body', { ...reminder, html: null, text: null }],
  ['several recipients', { ...reminder, to: ['a@x.io', 'b@x.io'] }],
  ['unknown retry profile', { ...reminder, retry_profile: 'aggressive' }],
  ['multi-line header value', { ...reminder, headers: { 'X-PF-Note': 'a\r\nBcc: x@example.com' } }],
];

let web: ReturnType<typeof fakeWeb>;
let sent: OutgoingMail[];
let mailer: Mailer & { send: ReturnType<typeof vi.fn<Mailer['send']>> };
let deferred: string[];
let handler: ReturnType<typeof createEmailHandler>;

beforeEach(() => {
  web = fakeWeb();
  sent = [];
  deferred = [];
  mailer = {
    send: vi.fn<Mailer['send']>(async (mail) => {
      sent.push(mail);
      return { messageId: '<smtp-9@mailpit>' };
    }),
    verify: async () => true,
    close: () => undefined,
  };
  const translator = new Translator('PayFlow Guard');
  const deps: JobDeps = {
    web,
    ai: fakeAi(),
    mailer,
    telegram: { sendMessage: async () => ({ message_id: 1 }) },
    sentMarkers: new MemoryCache(),
    webhookEvents: new MemoryWebhookEventStore(),
    telegramChats: new MemoryChatPreferences(),
    bot: { handleUpdate: async () => undefined },
    registry: createRegistry([], translator),
    translator,
    actions: new MemoryActionStore(),
    settings: { productSlug: 'payflow', productName: 'PayFlow Guard', mailFrom: '"PayFlow Guard" <no-reply@payflow.test>', notificationActionTtlS: 60 },
    hooks: { emailDeferred: (profile) => void deferred.push(profile) },
  };
  handler = createEmailHandler(deps);
});

const envelope = (payload: unknown) => ({ outbox_id: OUTBOX_ID, topic: 'email.send', workspace_id: 1, payload });

describe('email.send v2', () => {
  it('accepts the valid samples and rejects the invalid ones', () => {
    for (const sample of VALID) expect(emailV2PayloadSchema.safeParse(sample).success).toBe(true);
    for (const [name, sample] of INVALID) expect(emailV2PayloadSchema.safeParse(sample).success, name).toBe(false);
  });

  it('agrees with the shared contract samples when they are present', () => {
    const file = new URL('../../../../contracts/outbox/email-send.v2.samples.json', import.meta.url);
    if (!existsSync(file)) return;
    const fixture = JSON.parse(readFileSync(file, 'utf8')) as { valid: unknown[]; invalid: unknown[] };
    const payloadOf = (entry: unknown): unknown => (entry && typeof entry === 'object' && 'payload' in entry ? entry.payload : entry);
    for (const entry of fixture.valid) expect(emailV2PayloadSchema.safeParse(payloadOf(entry)).success).toBe(true);
    for (const entry of fixture.invalid) expect(emailV2PayloadSchema.safeParse(payloadOf(entry)).success).toBe(false);
  });

  it('maps a reminder to SMTP with the platform address, cc, reply-to, allowed headers and fetched attachments', async () => {
    await handler(envelope(reminder), jobContext());
    const mail = sent[0]!;
    expect(mail.from).toEqual({ name: 'Northstar Digital — Finance', address: 'no-reply@payflow.test' });
    expect(mail.to).toEqual({ address: 'ap@helios-media.example', name: 'Rachel Moore' });
    expect(mail.cc).toEqual(['cfo@helios-media.example']);
    expect(mail.replyTo).toBe('finance@northstar.example');
    expect(mail.headers).toMatchObject({
      'List-Unsubscribe': '<http://localhost:8100/i/tok123/reminders/one-click>',
      'List-Unsubscribe-Post': 'List-Unsubscribe=One-Click',
      'X-PF-Reminder': '41',
      'X-Reference': 'reminder:41',
      'Content-Language': 'en',
    });
    expect(web.fetchAttachment).toHaveBeenCalledWith('/internal/v1/payflow/invoices/118/pdf?workspace_id=1', 10 * 1024 * 1024, 'req-1');
    expect(mail.attachments?.map((attachment) => [attachment.filename, attachment.contentType, attachment.content.toString()])).toEqual([
      ['NS-2026-0118.pdf', 'application/pdf', '%PDF-1.7 demo'],
      ['note.txt', 'text/plain', 'hello'],
    ]);
    expect(web.reportDelivery).toHaveBeenCalledWith(
      { reference: { type: 'reminder', id: 41 }, channel: 'email', status: 'sent', provider_message_id: '<smtp-9@mailpit>' },
      'req-1',
    );
  });

  it('falls back to the product name as the sender name', async () => {
    await handler(envelope(VALID[1]), jobContext());
    expect(sent[0]!.from).toEqual({ name: 'PayFlow Guard', address: 'no-reply@payflow.test' });
    expect(mailboxAddress('no-reply@payflow.test')).toBe('no-reply@payflow.test');
  });

  it('rejects disallowed headers permanently without sending', async () => {
    await expect(handler(envelope(INVALID[0]![1]), jobContext())).rejects.toBeInstanceOf(UnrecoverableError);
    expect(mailer.send).not.toHaveBeenCalled();
  });

  it('reports deferred deliveries on retryable client-mail failures', async () => {
    mailer.send.mockRejectedValueOnce(Object.assign(new Error('452 4.2.2 Mailbox full'), { responseCode: 452 }));
    const before = Date.now();
    await expect(handler(envelope(reminder), jobContext({ attempt: 2, maxAttempts: 8 }))).rejects.toThrow('Mailbox full');
    const report = web.reportDelivery.mock.calls[0]![0];
    expect(report).toMatchObject({ reference: { type: 'reminder', id: 41 }, channel: 'email', status: 'deferred', attempt: 2, error: '452 4.2.2 Mailbox full' });
    const next = Date.parse(report.next_retry_at!);
    expect(next - before).toBeGreaterThanOrEqual(300_000 - 1000);
    expect(next - before).toBeLessThanOrEqual(300_000 + 5000);
    expect(deferred).toEqual(['client_mail']);
  });

  it('fails permanently on SMTP 5xx and on the final attempt', async () => {
    mailer.send.mockRejectedValueOnce(Object.assign(new Error('550 no such user'), { responseCode: 550 }));
    await expect(handler(envelope(reminder), jobContext())).rejects.toBeInstanceOf(UnrecoverableError);
    expect(web.reportDelivery).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'failed' }), 'req-1');
    expect(deferred).toEqual([]);
    mailer.send.mockRejectedValueOnce(Object.assign(new Error('451 try later'), { responseCode: 451 }));
    await expect(handler(envelope(reminder), jobContext({ attempt: 8, maxAttempts: 8, isFinalAttempt: true }))).rejects.toThrow('451');
    expect(web.reportDelivery).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'failed', error: '451 try later' }), 'req-1');
    expect(deferred).toEqual([]);
  });

  it('does not report deferrals for the default profile', async () => {
    mailer.send.mockRejectedValueOnce(Object.assign(new Error('452 busy'), { responseCode: 452 }));
    await expect(handler(envelope(VALID[1]), jobContext())).rejects.toThrow('452');
    expect(web.reportDelivery).not.toHaveBeenCalled();
  });
});

describe('client-mail retry profile', () => {
  it('waits 1 min, 5 min, 15 min, 1 h, 3 h, 6 h and 12 h between eight attempts', () => {
    expect([1, 2, 3, 4, 5, 6, 7].map(clientMailDelayMs)).toEqual([60, 300, 900, 3600, 10800, 21600, 43200].map((seconds) => seconds * 1000));
    expect(CLIENT_MAIL_ATTEMPTS).toBe(8);
    expect(customBackoff(3, CLIENT_MAIL_BACKOFF)).toBe(900_000);
  });

  it('is selected per outbox payload when the relay enqueues e-mails', () => {
    expect(jobOptionsFor('email.send', { retry_profile: 'client_mail' })).toEqual({ attempts: 8, backoff: { type: 'pf-client-mail' } });
    expect(jobOptionsFor('email.send', { retry_profile: 'default' })).toBeUndefined();
    expect(jobOptionsFor('telegram.notify', { retry_profile: 'client_mail' })).toBeUndefined();
  });
});
