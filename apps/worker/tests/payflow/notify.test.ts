import { describe, expect, it, vi } from 'vitest';
import { createRegistry } from '../../src/bot/registry.js';
import { MemoryActionStore, MemoryCache } from '../../src/bot/stores.js';
import { MemoryChatPreferences } from '../../src/db/telegramChats.js';
import { MemoryWebhookEventStore } from '../../src/db/webhookEvents.js';
import { Translator } from '../../src/i18n/index.js';
import type { JobDeps, TelegramSender } from '../../src/jobs/deps.js';
import { createTelegramNotifyHandler } from '../../src/jobs/telegramNotify.js';
import { fakeAi, fakeWeb, linkedContext } from '../helpers/fakes.js';
import { jobContext } from './fakes.js';

describe('telegram.notify buttons from PayFlow notifications', () => {
  it('stores payout callbacks for 7 days and turns local links into a text line', async () => {
    const web = fakeWeb(new Map([[5001, linkedContext()]]));
    const sendMessage = vi.fn<TelegramSender['sendMessage']>(async () => ({ message_id: 9 }));
    const actions = new MemoryActionStore();
    const translator = new Translator('PayFlow Guard');
    const deps: JobDeps = {
      web,
      ai: fakeAi(),
      mailer: { send: async () => ({ messageId: 'x' }), verify: async () => true, close: () => undefined },
      telegram: { sendMessage },
      sentMarkers: new MemoryCache(),
      webhookEvents: new MemoryWebhookEventStore(),
      telegramChats: new MemoryChatPreferences(),
      bot: { handleUpdate: async () => undefined },
      registry: createRegistry([], translator),
      translator,
      actions,
      settings: { productSlug: 'payflow', mailFrom: 'no-reply@payflow.test', notificationActionTtlS: 7 * 24 * 3600 },
    };
    const handler = createTelegramNotifyHandler(deps);
    await handler(
      {
        outbox_id: '0b1c2d3e-4f50-4617-8293-a4b5c6d7e8f9',
        topic: 'telegram.notify',
        workspace_id: 1,
        payload: {
          user_id: 7,
          text_html: '<b>Payout awaiting your approval</b>\nPO-2026-0089: <b>$7,200.00</b> to Ivan Smirnov (•••• 0047)',
          buttons: [
            { text: 'Approve', callback: 'pf.pa:89:1a2b3c4d' },
            { text: 'Reject', callback: 'pf.pr:89:1a2b3c4d' },
            { text: 'Open in app', url: 'http://localhost:8100/payouts/89' },
          ],
          reference: { type: 'payout', id: 89 },
        },
      },
      jobContext(),
    );
    const [, html, keyboard] = sendMessage.mock.calls[0]!;
    expect(html).toBe('<b>Payout awaiting your approval</b>\nPO-2026-0089: <b>$7,200.00</b> to Ivan Smirnov (•••• 0047)\n\nOpen in app: http://localhost:8100/payouts/89');
    const rows = keyboard!.inline_keyboard;
    expect(rows.map((row) => row.map((button) => button.text))).toEqual([['Approve'], ['Reject']]);
    const id = (rows[0]![0] as { callback_data: string }).callback_data.slice(2);
    const stored = actions.items.get(id)!;
    expect(stored.action).toMatchObject({ type: 'pf.pa', payload: { value: 'pf.pa:89:1a2b3c4d', arg: '89:1a2b3c4d' }, workspace_id: 1, telegram_user_id: 5001 });
    expect(stored.expiresAt - Date.now()).toBeGreaterThan(6.9 * 24 * 3600 * 1000);
  });
});
