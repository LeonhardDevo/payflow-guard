import type { FastifyInstance } from 'fastify';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { defaultModules } from '../src/bot/modules/index.js';
import type { BotModule } from '../src/bot/types.js';
import { UpstreamNetworkError } from '../src/clients/errors.js';
import { chatResponse, linkedContext } from './helpers/fakes.js';
import { createTestBot, devServer, sendDev, sentMessages, type TestBot } from './helpers/bot.js';

const ANNA = { id: 5001, username: 'anna', first_name: 'Anna', language_code: 'ru' };
const BOB = { id: 6002, username: 'bob', first_name: 'Bob', language_code: 'en' };

let bot: TestBot;
let server: FastifyInstance;

async function setup(options: Parameters<typeof createTestBot>[0] = {}): Promise<void> {
  bot = createTestBot(options);
  server = await devServer(bot.handle);
}

beforeEach(async () => {
  await setup();
});

afterEach(async () => {
  await server.close();
});

describe('dev route', () => {
  it('rejects malformed input', async () => {
    const response = await server.inject({ method: 'POST', url: '/dev/telegram/update', payload: { text: 'hi' } });
    expect(response.statusCode).toBe(422);
  });

  it('accepts a full Telegram Update', async () => {
    const response = await server.inject({
      method: 'POST',
      url: '/dev/telegram/update',
      payload: {
        update_id: 77,
        message: { message_id: 1, date: 1, chat: { id: BOB.id, type: 'private' }, from: { ...BOB, is_bot: false }, text: '/help', entities: [{ type: 'bot_command', offset: 0, length: 5 }] },
      },
    });
    expect(response.statusCode).toBe(200);
    const body = response.json<{ update_id: number; outbound: Array<{ method: string }> }>();
    expect(body.update_id).toBe(77);
    expect(body.outbound.map((call) => call.method)).toEqual(['sendMessage']);
  });
});

describe('/start linking', () => {
  it('links the account with a deep-link token', async () => {
    const outbound = await sendDev(server, { from: ANNA, text: '/start tok_abcdef123456' });
    expect(bot.web.linkTelegram).toHaveBeenCalledWith(
      { token: 'tok_abcdef123456', telegram_user_id: ANNA.id, chat_id: ANNA.id, username: 'anna', language_code: 'ru' },
      expect.any(String),
    );
    const [message] = sentMessages(outbound);
    expect(message?.text).toContain('Готово! Telegram привязан к Acme Desk');
    expect(message?.text).toContain('<b>Anna Petrova</b>');
    expect(message?.text).toContain('Demo Studio');
    expect(outbound[0]).toMatchObject({ payload: { parse_mode: 'HTML', chat_id: ANNA.id } });
  });

  it('refreshes the cached account after linking', async () => {
    await sendDev(server, { from: ANNA, text: 'hello' });
    bot.contexts.set(ANNA.id, linkedContext());
    await sendDev(server, { from: ANNA, text: '/start tok_abcdef123456' });
    const outbound = await sendDev(server, { from: ANNA, text: 'What is the refund policy?' });
    expect(bot.ai.chat).toHaveBeenCalledTimes(1);
    expect(sentMessages(outbound).at(-1)?.text).toContain('Refund policy');
  });

  it('explains invalid or expired tokens in the user language', async () => {
    bot.web.linkTelegram.mockResolvedValueOnce({ ok: false, reason: 'invalid_token' });
    const outbound = await sendDev(server, { from: BOB, text: '/start tok_expired_0001' });
    const [message] = sentMessages(outbound);
    expect(message?.text).toContain('This link is invalid or has expired');
    expect(message?.text).toContain('Settings: http://127.0.0.1:8100/settings/integrations');
  });

  it('rejects malformed tokens without calling web', async () => {
    const outbound = await sendDev(server, { from: BOB, text: '/start bad!' });
    expect(bot.web.linkTelegram).not.toHaveBeenCalled();
    expect(sentMessages(outbound)[0]?.text).toContain('invalid or has expired');
  });

  it('reports web outages instead of crashing', async () => {
    bot.web.linkTelegram.mockRejectedValueOnce(new UpstreamNetworkError('web', 'POST', '/internal/v1/telegram/link', new Error('ECONNREFUSED')));
    const outbound = await sendDev(server, { from: BOB, text: '/start tok_abcdef123456' });
    expect(sentMessages(outbound)[0]?.text).toContain('temporarily unavailable');
  });

  it('greets unlinked users with linking instructions and a public settings button', async () => {
    await server.close();
    await setup({ webPublicUrl: 'https://app.acme.example' });
    const outbound = await sendDev(server, { from: BOB, text: '/start' });
    const [message] = sentMessages(outbound);
    expect(message?.text).toContain('link your Telegram account');
    expect(message?.reply_markup?.inline_keyboard[0]?.[0]).toMatchObject({ url: 'https://app.acme.example/settings/integrations' });
  });

  it('welcomes linked users back', async () => {
    bot.contexts.set(ANNA.id, linkedContext());
    const outbound = await sendDev(server, { from: ANNA, text: '/start' });
    expect(sentMessages(outbound)[0]?.text).toContain('С возвращением, <b>Anna Petrova</b>');
  });
});

describe('unlinked users', () => {
  it('get a localized link-your-account message for free text', async () => {
    const outbound = await sendDev(server, { from: ANNA, text: 'Какой лимит?' });
    expect(bot.ai.chat).not.toHaveBeenCalled();
    const [message] = sentMessages(outbound);
    expect(message?.text).toContain('Ваш Telegram ещё не привязан к Acme Desk');
    expect(message?.text).toContain('Настройки: http://127.0.0.1:8100/settings/integrations');
  });

  it('see an English message when their Telegram language is English', async () => {
    const outbound = await sendDev(server, { from: BOB, text: 'hello' });
    expect(sentMessages(outbound)[0]?.text).toContain('Your Telegram account is not linked to Acme Desk yet');
  });
});

describe('assistant', () => {
  beforeEach(() => {
    bot.contexts.set(ANNA.id, linkedContext());
  });

  it('forwards free text to ai with the user role and permissions', async () => {
    const outbound = await sendDev(server, { from: ANNA, text: 'What is the refund policy?' });
    expect(bot.ai.chat).toHaveBeenCalledWith(
      {
        workspace_id: 3,
        user: { id: 7, role: 'admin', locale: 'ru', permissions: ['documents.view', 'invoices.approve'] },
        channel: 'telegram',
        conversation_id: `tg-${ANNA.id}`,
        message: 'What is the refund policy?',
        ai_external_allowed: false,
      },
      expect.any(String),
    );
    expect(outbound[0]).toMatchObject({ method: 'sendChatAction', payload: { action: 'typing', chat_id: ANNA.id } });
    const reply = sentMessages(outbound)[0]!;
    expect(reply.text).toContain('Refunds are processed within <b>5 days</b> [1].');
    expect(reply.text).toContain('<b>2 источника</b>');
    expect(reply.text).toContain('[1] Refund policy, с. 2');
    expect(reply.text).toContain('[2] <a href="https://docs.example.com/faq">Billing FAQ</a>');
  });

  it('logs the question to web', async () => {
    await sendDev(server, { from: ANNA, text: 'What is the refund policy?' });
    expect(bot.web.logAiQuery).toHaveBeenCalledWith(
      expect.objectContaining({ workspace_id: 3, user_id: 7, channel: 'telegram', question: 'What is the refund policy?', refused: false }),
      expect.any(String),
    );
  });

  it('renders ai actions as inline buttons backed by stored actions', async () => {
    bot.ai.chat.mockResolvedValueOnce(
      chatResponse({
        refused: true,
        citations: [],
        answer_html: '',
        answer_markdown: '',
        actions: [
          { type: 'escalate', label: 'Ask a manager', payload: { reason: 'not_found' } },
          { type: 'open_url', label: 'Open policy', payload: { url: 'https://docs.example.com/policy' } },
        ],
      }),
    );
    const outbound = await sendDev(server, { from: ANNA, text: 'Unknown thing?' });
    const reply = sentMessages(outbound)[0]!;
    expect(reply.text).toContain('Я не нашёл ответа в утверждённых документах.');
    const [[escalate], [open]] = reply.reply_markup!.inline_keyboard as [[Record<string, string>], [Record<string, string>]];
    expect(escalate).toMatchObject({ text: 'Ask a manager' });
    expect(escalate.callback_data).toMatch(/^a:[A-Za-z0-9]{12}$/);
    expect(open).toMatchObject({ text: 'Open policy', url: 'https://docs.example.com/policy' });
    const id = escalate.callback_data!.slice(2);
    expect(await bot.actions.get(id)).toMatchObject({ type: 'escalate', telegram_user_id: ANNA.id, workspace_id: 3, user_id: 7 });
  });

  it('forwards pressed domain actions to web and answers the callback', async () => {
    const id = await bot.actions.put(
      { type: 'escalate', payload: { reason: 'x' }, telegram_user_id: ANNA.id, chat_id: ANNA.id, workspace_id: 3, user_id: 7, locale: 'ru', created_at: Date.now() },
      60,
    );
    bot.web.telegramAction.mockResolvedValueOnce({ ok: true, message: 'Передано менеджеру', message_html: '<b>Заявка #12</b> создана' });
    const outbound = await sendDev(server, { from: ANNA, callback_data: `a:${id}` });
    expect(bot.web.telegramAction).toHaveBeenCalledWith(
      { type: 'escalate', payload: { reason: 'x' }, telegram_user_id: ANNA.id, chat_id: ANNA.id, workspace_id: 3, user_id: 7, locale: 'ru' },
      expect.any(String),
    );
    expect(outbound.find((call) => call.method === 'answerCallbackQuery')?.payload).toMatchObject({ text: 'Передано менеджеру' });
    expect(sentMessages(outbound)[0]?.text).toBe('<b>Заявка #12</b> создана');
  });

  it('runs locally registered actions (follow-up questions)', async () => {
    const id = await bot.actions.put(
      { type: 'ask', payload: { question: 'And for annual plans?' }, telegram_user_id: ANNA.id, chat_id: ANNA.id, workspace_id: 3, user_id: 7, locale: 'ru', created_at: Date.now() },
      60,
    );
    const outbound = await sendDev(server, { from: ANNA, callback_data: `a:${id}` });
    expect(bot.ai.chat).toHaveBeenCalledWith(expect.objectContaining({ message: 'And for annual plans?' }), expect.any(String));
    expect(outbound[0]?.method).toBe('answerCallbackQuery');
    expect(sentMessages(outbound)[0]?.text).toBe('<i>And for annual plans?</i>');
  });

  it('refuses buttons that belong to another user and expired buttons', async () => {
    const id = await bot.actions.put(
      { type: 'escalate', payload: {}, telegram_user_id: ANNA.id, chat_id: ANNA.id, workspace_id: 3, user_id: 7, locale: 'ru', created_at: Date.now() },
      60,
    );
    const forbidden = await sendDev(server, { from: BOB, callback_data: `a:${id}` });
    expect(forbidden[0]).toMatchObject({ method: 'answerCallbackQuery', payload: { text: 'This button belongs to another user.', show_alert: true } });
    const expired = await sendDev(server, { from: ANNA, callback_data: 'a:doesnotexist1' });
    expect(expired[0]?.payload).toMatchObject({ text: 'Срок действия кнопки истёк. Задайте вопрос ещё раз.' });
    expect(bot.web.telegramAction).not.toHaveBeenCalled();
  });

  it('answers with a localized error when ai is down', async () => {
    bot.ai.chat.mockRejectedValueOnce(new UpstreamNetworkError('ai', 'POST', '/v1/chat', new Error('timeout')));
    const outbound = await sendDev(server, { from: ANNA, text: 'question' });
    expect(sentMessages(outbound)[0]?.text).toContain('Acme Desk временно недоступен');
  });

  it('asks to pick a workspace first', async () => {
    bot.contexts.set(ANNA.id, linkedContext({ workspace: null }));
    const outbound = await sendDev(server, { from: ANNA, text: 'question' });
    expect(bot.ai.chat).not.toHaveBeenCalled();
    expect(sentMessages(outbound)[0]?.text).toContain('Сначала выберите пространство');
  });

  it('ignores free text in group chats', async () => {
    const outbound = await sendDev(server, { from: ANNA, chat_id: -100123, text: 'hello group' });
    expect(outbound).toEqual([]);
  });
});

describe('/lang, /help, /whoami', () => {
  it('offers an inline language keyboard', async () => {
    const outbound = await sendDev(server, { from: BOB, text: '/lang' });
    const [message] = sentMessages(outbound);
    expect(message?.text).toBe('Choose your language:');
    expect(message?.reply_markup?.inline_keyboard[0]).toEqual([
      { text: 'Русский', callback_data: 'lang:ru' },
      { text: 'English', callback_data: 'lang:en' },
    ]);
  });

  it('switches language and persists the choice', async () => {
    const outbound = await sendDev(server, { from: BOB, callback_data: 'lang:ru' });
    expect(bot.preferences.locales.get(BOB.id)).toBe('ru');
    expect(outbound.map((call) => call.method)).toEqual(['answerCallbackQuery', 'editMessageText']);
    expect(outbound[1]?.payload).toMatchObject({ text: 'Язык переключён на русский.' });
    const help = await sendDev(server, { from: BOB, text: '/help' });
    expect(sentMessages(help)[0]?.text).toContain('Команды:');
  });

  it('lists registered commands in /help', async () => {
    const outbound = await sendDev(server, { from: BOB, text: '/help' });
    const text = sentMessages(outbound)[0]?.text ?? '';
    for (const command of ['/start', '/help', '/lang', '/whoami']) expect(text).toContain(command);
  });

  it('shows the linked account in /whoami', async () => {
    bot.contexts.set(BOB.id, linkedContext({ user: { id: 9, name: 'Bob <Admin>', locale: 'en' } }));
    const outbound = await sendDev(server, { from: BOB, text: '/whoami' });
    const text = sentMessages(outbound)[0]?.text ?? '';
    expect(text).toContain('<b>Bob &lt;Admin&gt;</b>');
    expect(text).toContain('Role: <b>admin</b>');
    expect(text).toContain('documents.view, invoices.approve');
  });

  it('answers unknown commands and non-text messages', async () => {
    const unknown = await sendDev(server, { from: BOB, text: '/nope' });
    expect(sentMessages(unknown)[0]?.text).toContain('I do not know this command');
  });
});

describe('rate limiting', () => {
  it('warns once and then drops messages over the per-chat limit', async () => {
    await server.close();
    await setup({ rateLimit: 2 });
    await sendDev(server, { from: BOB, text: '/help' });
    await sendDev(server, { from: BOB, text: '/help' });
    const warned = await sendDev(server, { from: BOB, text: '/help' });
    expect(sentMessages(warned)[0]?.text).toContain('too fast');
    const dropped = await sendDev(server, { from: BOB, text: '/help' });
    expect(dropped).toEqual([]);
  });
});

describe('module registry', () => {
  it('lets domain modules add commands, strings and action handlers', async () => {
    const invoices: BotModule = {
      name: 'invoices',
      commands: [{ command: 'invoices', descriptionKey: 'invoices.command' }],
      messages: { en: { invoices: { command: 'Open invoices', list: 'You have {count} open invoices' } }, ru: { invoices: { command: 'Открытые счета', list: 'Открытых счетов: {count}' } } },
      register(composer) {
        composer.command('invoices', async (ctx) => {
          await ctx.reply(ctx.t('invoices.list', { count: 3 }));
        });
      },
      actions: {
        'invoice.approve': async (ctx, action) => {
          await ctx.reply(`approved ${String(action.payload.arg)}`);
        },
      },
    };
    const [core, privacy, assistant] = defaultModules();
    await server.close();
    await setup({ modules: [core!, privacy!, invoices, assistant!] });
    const list = await sendDev(server, { from: BOB, text: '/invoices' });
    expect(sentMessages(list)[0]?.text).toBe('You have 3 open invoices');
    const help = await sendDev(server, { from: BOB, text: '/help' });
    expect(sentMessages(help)[0]?.text).toContain('/invoices — Open invoices');

    const id = await bot.actions.put(
      { type: 'invoice.approve', payload: { value: 'invoice.approve:42', arg: '42' }, telegram_user_id: null, chat_id: BOB.id, workspace_id: 1, user_id: 1, locale: 'en', created_at: Date.now() },
      60,
    );
    const pressed = await sendDev(server, { from: BOB, callback_data: `a:${id}` });
    expect(sentMessages(pressed)[0]?.text).toBe('approved 42');
    expect(bot.web.telegramAction).not.toHaveBeenCalled();
  });
});
