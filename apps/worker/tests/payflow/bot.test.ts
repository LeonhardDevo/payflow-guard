import type { FastifyInstance } from 'fastify';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type { OutboundCall } from '../../src/bot/capture.js';
import { defaultModules } from '../../src/bot/modules/index.js';
import { MemoryCache } from '../../src/bot/stores.js';
import { UpstreamNetworkError } from '../../src/clients/errors.js';
import type { TelegramContext } from '../../src/clients/web.js';
import { PAYFLOW_MESSAGES } from '../../src/domain/payflow/bot/messages.js';
import { replyKey } from '../../src/domain/payflow/bot/module.js';
import en from '../../src/i18n/en.json' with { type: 'json' };
import ru from '../../src/i18n/ru.json' with { type: 'json' };
import { linkedContext } from '../helpers/fakes.js';
import { createTestBot, devServer, sendDev, type TestBot } from '../helpers/bot.js';
import { fakePayflowApi, view } from './fakes.js';

const ANNA = { id: 700000001, username: 'anna', first_name: 'Anna', language_code: 'en' };
const DMITRY = { id: 700000003, username: 'dmitry', first_name: 'Dmitry', language_code: 'ru' };
const LAURA = { id: 700000004, username: 'laura', first_name: 'Laura', language_code: 'en' };
const STRANGER = { id: 999, first_name: 'Eve', language_code: 'en' };

const ALL = ['invoices.view', 'payouts.view', 'vendors.view', 'clients.view', 'reminders.send', 'assistant.use'];

function account(id: number, name: string, locale: 'en' | 'ru', permissions: string[], extra: Partial<TelegramContext> = {}): TelegramContext {
  return linkedContext({
    user: { id: id - 700000000, name, locale },
    workspace: { id: 1, name: 'Northstar Digital', plan: 'pro', ai_external_allowed: false },
    role: 'approver',
    permissions,
    telegram: { user_id: id, chat_id: id, username: null },
    ...extra,
  });
}

let bot: TestBot;
let server: FastifyInstance;
let api: ReturnType<typeof fakePayflowApi>;
let cache: MemoryCache;

beforeEach(async () => {
  api = fakePayflowApi();
  cache = new MemoryCache();
  const contexts = new Map<number, TelegramContext>([
    [ANNA.id, account(ANNA.id, 'Anna Petrova', 'en', ALL)],
    [DMITRY.id, account(DMITRY.id, 'Dmitry Sokolov', 'ru', ['payouts.view', 'invoices.view', 'assistant.use'])],
    [LAURA.id, account(LAURA.id, 'Laura Chen', 'en', ['clients.view', 'assistant.use'], { ai_quota: { used: 50, limit: 50 } })],
  ]);
  bot = createTestBot({ contexts, webPublicUrl: 'http://localhost:8100', modules: defaultModules({ payflow: { api, cache } }) });
  server = await devServer(bot.handle);
});

afterEach(async () => {
  await server.close();
});

type Keyboard = Array<Array<{ text: string; callback_data?: string; url?: string }>>;

function messages(outbound: OutboundCall[], method = 'sendMessage'): Array<{ text: string; reply_markup?: { inline_keyboard?: Keyboard; force_reply?: boolean } }> {
  return outbound.filter((call) => call.method === method).map((call) => call.payload as never);
}

function callbackFor(outbound: OutboundCall[], label: string, method = 'sendMessage'): string {
  for (const message of messages(outbound, method)) {
    for (const row of message.reply_markup?.inline_keyboard ?? []) {
      for (const button of row) if (button.text === label && button.callback_data) return button.callback_data;
    }
  }
  throw new Error(`no button "${label}"`);
}

describe('PayFlow bot commands', () => {
  it('lists only the commands the linked user may run', async () => {
    const outbound = await sendDev(server, { from: DMITRY, text: '/help' });
    const text = messages(outbound)[0]!.text;
    expect(text).toContain('/approvals — Выплаты, ожидающие согласования');
    expect(text).toContain('/overdue — Самые просроченные счета');
    expect(text).toContain('/digest — Сводка на сегодня');
    expect(text).not.toContain('/changes');
    expect(text).not.toContain('/remind');
  });

  it('re-checks the permission before calling web', async () => {
    const outbound = await sendDev(server, { from: DMITRY, text: '/changes' });
    expect(messages(outbound)[0]!.text).toContain('Ваша роль в этом пространстве не позволяет');
    expect(api.botView).not.toHaveBeenCalled();
  });

  it('renders a view with stored-action buttons and keeps local links as text', async () => {
    api.botView.mockResolvedValueOnce(
      view('<b>Overdue invoices</b>\n1. NS-2026-0118 — Helios Media Group, 52 d, $12,400.00', [
        { text: 'Remind NS-2026-0118', callback: 'pf.rm:118' },
        { text: 'Open in app', url: 'http://localhost:8100/invoices/118' },
        { text: 'Open dashboard', url: 'https://app.payflow.example/dashboard' },
      ]),
    );
    const outbound = await sendDev(server, { from: ANNA, text: '/overdue' });
    expect(api.botView).toHaveBeenCalledWith('overdue', ANNA.id, null, expect.any(String));
    const message = messages(outbound)[0]!;
    expect(message.text).toContain('NS-2026-0118 — Helios Media Group');
    expect(message.text).toContain('Open in app: http://localhost:8100/invoices/118');
    const keyboard = message.reply_markup!.inline_keyboard!;
    expect(keyboard).toHaveLength(2);
    expect(keyboard[0]![0]!.callback_data).toMatch(/^a:[A-Za-z0-9]{12}$/);
    expect(keyboard[1]![0]).toEqual({ text: 'Open dashboard', url: 'https://app.payflow.example/dashboard' });
    const stored = await bot.actions.get(keyboard[0]![0]!.callback_data!.slice(2));
    expect(stored).toMatchObject({ type: 'pf.rm', payload: { value: 'pf.rm:118', arg: '118' }, telegram_user_id: ANNA.id, workspace_id: 1, user_id: 1, locale: 'en' });
  });

  it('asks for an argument and validates invoice numbers', async () => {
    expect(messages(await sendDev(server, { from: ANNA, text: '/client' }))[0]!.text).toContain('/client Helios Media');
    expect(messages(await sendDev(server, { from: ANNA, text: '/remind 118' }))[0]!.text).toContain('/remind NS-2026-0118');
    await sendDev(server, { from: ANNA, text: '/client Helios Media' });
    expect(api.botView).toHaveBeenCalledWith('client', ANNA.id, 'Helios Media', expect.any(String));
  });

  it('answers unlinked users with the settings link and survives web outages', async () => {
    const unlinked = await sendDev(server, { from: STRANGER, text: '/approvals' });
    expect(messages(unlinked)[0]!.text).toContain('Settings: http://localhost:8100/settings/integrations');
    api.botView.mockRejectedValueOnce(new UpstreamNetworkError('web', 'GET', '/x', new Error('down')));
    const down = await sendDev(server, { from: ANNA, text: '/digest' });
    expect(messages(down)[0]!.text).toContain('temporarily unavailable');
    api.botView.mockResolvedValueOnce({ code: 'not_linked' });
    const gone = await sendDev(server, { from: ANNA, text: '/digest' });
    expect(messages(gone)[0]!.text).toContain('not linked');
  });
});

describe('payout decisions through stored actions', () => {
  async function approvalsMessage(): Promise<OutboundCall[]> {
    api.botView.mockResolvedValueOnce(
      view('<b>Waiting for your approval</b>\nPO-2026-0089: $7,200.00 to Ivan Smirnov (•••• 0047)', [
        { text: 'Approve', callback: 'pf.pa:89:1a2b3c4d' },
        { text: 'Reject', callback: 'pf.pr:89:1a2b3c4d' },
      ]),
    );
    return sendDev(server, { from: DMITRY, text: '/approvals' });
  }

  it('asks for confirmation, then records the approval', async () => {
    const list = await approvalsMessage();
    api.payoutDecision.mockResolvedValueOnce(
      view('Approve PO-2026-0089 — $7,200.00 to Ivan Smirnov (•••• 0047)?', [
        { text: 'Confirm approval', callback: 'pf.pac:89:1a2b3c4d' },
        { text: 'Cancel', callback: 'pf.x' },
      ], { ok: false, code: 'confirm_required' }),
    );
    const prompt = await sendDev(server, { from: DMITRY, callback_data: callbackFor(list, 'Approve') });
    expect(api.payoutDecision).toHaveBeenLastCalledWith(89, { telegram_user_id: DMITRY.id, decision: 'approve', fp8: '1a2b3c4d', confirmed: false }, expect.any(String));
    expect(prompt.map((call) => call.method)).toEqual(['answerCallbackQuery', 'editMessageText']);
    const edited = messages(prompt, 'editMessageText')[0]!;
    expect(edited.text).toBe('Approve PO-2026-0089 — $7,200.00 to Ivan Smirnov (•••• 0047)?');

    api.payoutDecision.mockResolvedValueOnce(view('Approved — 1/1, ready to release', [], { ok: true, status: 'approved' }));
    const done = await sendDev(server, { from: DMITRY, callback_data: callbackFor(prompt, 'Confirm approval', 'editMessageText') });
    expect(api.payoutDecision).toHaveBeenLastCalledWith(89, { telegram_user_id: DMITRY.id, decision: 'approve', fp8: '1a2b3c4d', confirmed: true }, expect.any(String));
    const result = messages(done, 'editMessageText')[0]!;
    expect(result.text).toBe('Approved — 1/1, ready to release');
    expect(result.reply_markup?.inline_keyboard).toEqual([]);
  });

  it('shows the web-only answer with the payout link', async () => {
    const list = await approvalsMessage();
    api.payoutDecision.mockResolvedValueOnce(view('High risk — approve in the web app with a second-factor confirmation.', [{ text: 'Open in app', url: 'http://localhost:8100/payouts/89' }], { ok: false, code: 'web_only' }));
    const outbound = await sendDev(server, { from: DMITRY, callback_data: callbackFor(list, 'Approve') });
    expect(messages(outbound, 'editMessageText')[0]!.text).toContain('Open in app: http://localhost:8100/payouts/89');
  });

  it('collects the rejection reason through a ForceReply and then lets other text reach the assistant', async () => {
    const list = await approvalsMessage();
    const prompt = await sendDev(server, { from: DMITRY, callback_data: callbackFor(list, 'Reject') });
    const ask = messages(prompt)[0]!;
    expect(ask.text).toBe('Ответьте на это сообщение и укажите причину отклонения выплаты.');
    expect(ask.reply_markup).toMatchObject({ force_reply: true });
    expect(await cache.get(replyKey(DMITRY.id))).toBe(JSON.stringify({ kind: 'reject', payout_id: 89, fp8: '1a2b3c4d' }));

    api.payoutDecision.mockResolvedValueOnce(view('<b>PO-2026-0089: отклонена</b> — Dmitry Sokolov: дубликат.', [], { ok: true, status: 'rejected' }));
    const result = await sendDev(server, { from: DMITRY, text: 'Дубликат PO-2026-0083' });
    expect(api.payoutDecision).toHaveBeenLastCalledWith(
      89,
      { telegram_user_id: DMITRY.id, decision: 'reject', fp8: '1a2b3c4d', comment: 'Дубликат PO-2026-0083', confirmed: true },
      expect.any(String),
    );
    expect(messages(result)[0]!.text).toContain('отклонена');
    expect(await cache.get(replyKey(DMITRY.id))).toBeNull();

    await sendDev(server, { from: DMITRY, text: 'Какой счёт просрочен сильнее всего?' });
    expect(bot.ai.chat).toHaveBeenCalledTimes(1);
  });

  it('refuses buttons owned by another user and expired buttons', async () => {
    const list = await approvalsMessage();
    const foreign = await sendDev(server, { from: ANNA, callback_data: callbackFor(list, 'Approve') });
    expect(foreign[0]).toMatchObject({ method: 'answerCallbackQuery', payload: { text: 'This button belongs to another user.' } });
    const expired = await sendDev(server, { from: ANNA, callback_data: 'a:ZZZZZZZZZZZZ' });
    expect(expired[0]).toMatchObject({ method: 'answerCallbackQuery', payload: { text: 'This button has expired. Send /approvals or /overdue for a fresh list.' } });
    expect(api.payoutDecision).not.toHaveBeenCalled();
  });

  it('rejects tampered payout arguments without calling web', async () => {
    const id = await bot.actions.put(
      { type: 'pf.pa', payload: { value: 'pf.pa:89:zz', arg: '89:zz' }, telegram_user_id: ANNA.id, chat_id: ANNA.id, workspace_id: 1, user_id: 1, locale: 'en', created_at: Date.now() },
      60,
    );
    const outbound = await sendDev(server, { from: ANNA, callback_data: `a:${id}` });
    expect(messages(outbound, 'editMessageText')[0]!.text).toContain('This button is no longer valid');
    expect(api.payoutDecision).not.toHaveBeenCalled();
  });
});

describe('reminders, clients and alerts through stored actions', () => {
  it('previews, sends and force-sends a reminder', async () => {
    api.remindInvoice.mockResolvedValueOnce(view('Final reminder for NS-2026-0118 — subject: Final notice', [{ text: 'Send', callback: 'pf.rmc:118' }, { text: 'Cancel', callback: 'pf.x' }], { ok: false, code: 'confirm_required' }));
    const preview = await sendDev(server, { from: ANNA, text: '/remind ns-2026-0118' });
    expect(api.remindInvoice).toHaveBeenLastCalledWith({ telegram_user_id: ANNA.id, invoice: 'NS-2026-0118', confirmed: false }, expect.any(String));

    api.remindInvoice.mockResolvedValueOnce(view('A reminder was sent 6 hours ago.', [{ text: 'Send anyway', callback: 'pf.rmf:118' }], { ok: false, code: 'recent_reminder' }));
    const recent = await sendDev(server, { from: ANNA, callback_data: callbackFor(preview, 'Send') });
    expect(api.remindInvoice).toHaveBeenLastCalledWith({ telegram_user_id: ANNA.id, invoice_id: 118, confirmed: true }, expect.any(String));

    api.remindInvoice.mockResolvedValueOnce(view('Reminder queued', [], { ok: true }));
    const forced = await sendDev(server, { from: ANNA, callback_data: callbackFor(recent, 'Send anyway', 'editMessageText') });
    expect(api.remindInvoice).toHaveBeenLastCalledWith({ telegram_user_id: ANNA.id, invoice_id: 118, confirmed: true, force: true }, expect.any(String));
    expect(messages(forced, 'editMessageText')[0]!.text).toBe('Reminder queued');
  });

  it('cancels, opens a disambiguated client and acknowledges an alert', async () => {
    api.botView.mockResolvedValueOnce(view('Which client?', [{ text: 'Helios Media Group', callback: 'pf.cl:1' }, { text: 'Cancel', callback: 'pf.x' }], { code: 'ambiguous' }));
    const ambiguous = await sendDev(server, { from: ANNA, text: '/client Helios' });
    api.botView.mockResolvedValueOnce(view('<b>Helios Media Group</b> — chronic late payer'));
    const client = await sendDev(server, { from: ANNA, callback_data: callbackFor(ambiguous, 'Helios Media Group') });
    expect(api.botView).toHaveBeenLastCalledWith('client', ANNA.id, '1', expect.any(String));
    expect(messages(client, 'editMessageText')[0]!.text).toContain('chronic late payer');

    const cancelled = await sendDev(server, { from: ANNA, callback_data: callbackFor(ambiguous, 'Cancel') });
    expect(messages(cancelled, 'editMessageText')[0]!.text).toBe('Cancelled.');

    const alertId = await bot.actions.put(
      { type: 'pf.ack', payload: { value: 'pf.ack:5', arg: '5' }, telegram_user_id: ANNA.id, chat_id: ANNA.id, workspace_id: 1, user_id: 1, locale: 'en', created_at: Date.now() },
      60,
    );
    const ack = await sendDev(server, { from: ANNA, callback_data: `a:${alertId}` });
    expect(api.acknowledgeAlert).toHaveBeenCalledWith(5, ANNA.id, expect.any(String));
    expect(messages(ack, 'editMessageText')[0]!.text).toBe('Acknowledged by Anna Petrova');
  });
});

describe('assistant quota and privacy notice', () => {
  it('refuses free-text questions when the monthly AI quota is used up', async () => {
    const outbound = await sendDev(server, { from: LAURA, text: 'Who pays late?' });
    expect(messages(outbound)[0]!.text).toContain('(50 of 50)');
    expect(bot.ai.chat).not.toHaveBeenCalled();
  });

  it('adds the payout audit line to the first-contact notice in both locales', () => {
    expect(String((PAYFLOW_MESSAGES.en.privacy as Record<string, string>).notice)).toContain('recorded with channel Telegram in the workspace audit log');
    expect(String((PAYFLOW_MESSAGES.ru.privacy as Record<string, string>).notice)).toContain('журнал аудита с каналом Telegram');
  });

  it('ships no emoji in bot texts', () => {
    const emoji = /\p{Extended_Pictographic}|️/u;
    const strings: string[] = [];
    const walk = (node: unknown): void => {
      if (typeof node === 'string') strings.push(node);
      else if (node && typeof node === 'object') for (const value of Object.values(node)) walk(value);
    };
    walk(PAYFLOW_MESSAGES);
    walk(en);
    walk(ru);
    const offending = strings.filter((value) => [...value].some((char) => !['©', '®', '™'].includes(char) && emoji.test(char)));
    expect(offending).toEqual([]);
  });
});
