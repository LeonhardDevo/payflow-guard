import { expect, test } from '../fixtures.ts';
import { messageUpdate, outboundText, randomTelegramId, startToken, type OutboundCall, type TelegramUser } from '../support/telegram.ts';

test.describe('telegram integration', () => {
  test('/start <token> via the worker dev route links the account', { tag: '@flow' }, async ({ page, app, owner, request }) => {
    await page.goto('/settings/integrations');
    await expect(page.getByTestId('telegram-status')).toHaveAttribute('data-linked', 'false');

    const linkResponse = page.waitForResponse(
      (response) => response.request().method() === 'POST' && /\/settings\/integrations\/telegram\/link$/.test(new URL(response.url()).pathname),
    );
    await page.getByTestId('telegram-link-button').click();
    const { url } = (await (await linkResponse).json()) as { url: string; expires_at: string };
    const token = startToken(url);
    expect(token, `deep link without start token: ${url}`).toBeTruthy();

    const user: TelegramUser = { id: randomTelegramId(), username: `e2e_${Date.now().toString(36)}`, firstName: 'E2E', languageCode: app.locale };
    const send = async (text: string): Promise<OutboundCall[]> => {
      const response = await request.post(`${app.config.workerURL}/dev/telegram/update`, { data: messageUpdate(user, text) });
      expect(response.ok(), `dev/telegram/update → HTTP ${response.status()}: ${await response.text()}`).toBeTruthy();
      return ((await response.json()) as { outbound: OutboundCall[] }).outbound;
    };

    const startReply = await send(`/start ${token}`);
    expect(startReply.some((call) => call.method === 'sendMessage'), 'bot did not answer /start').toBeTruthy();

    await page.reload();
    await expect(page.getByTestId('telegram-status')).toHaveAttribute('data-linked', 'true');
    await expect(page.getByTestId('telegram-username')).toContainText(user.username);
    await app.shot('integrations-telegram-linked');

    const whoami = outboundText(await send('/whoami'));
    expect(whoami).toContain(owner.workspace);
  });

  test('a used or unknown token does not link anything', { tag: '@flow' }, async ({ page, app, owner: _owner, request }) => {
    const user: TelegramUser = { id: randomTelegramId(), username: `e2e_bad_${Date.now().toString(36)}`, firstName: 'E2E', languageCode: app.locale };
    const response = await request.post(`${app.config.workerURL}/dev/telegram/update`, {
      data: messageUpdate(user, '/start not-a-real-token'),
    });
    expect(response.ok()).toBeTruthy();
    const { outbound } = (await response.json()) as { outbound: OutboundCall[] };
    expect(outbound.some((call) => call.method === 'sendMessage'), 'bot should explain the invalid token').toBeTruthy();

    await page.goto('/settings/integrations');
    await expect(page.getByTestId('telegram-status')).toHaveAttribute('data-linked', 'false');
  });
});
