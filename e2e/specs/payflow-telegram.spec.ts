import {
  callbackButtons,
  clearTelegramOutbox,
  expect,
  expectNoEmoji,
  messageTexts,
  PF,
  pfLogin,
  resetDemo,
  telegram,
  test,
  type TelegramSender,
} from '../support/payflow.ts';
import type { Page } from '@playwright/test';
import { emojiIn } from '../support/guards.ts';

test.describe.configure({ mode: 'serial', timeout: 300_000 });

const DMITRY: TelegramSender = { id: 700000003, language: 'ru' };
const ACTION_RE = /^a:[A-Za-z0-9]{6,32}$/;

async function openPayout(page: Page, number: string): Promise<void> {
  await page.goto(`/payouts?tab=all&q=${encodeURIComponent(number)}`);
  await page.locator(`[data-test="payout-row"][data-number="${number}"]`).first().click();
  await expect(page).toHaveURL(/\/payouts\/\d+$/);
}

test.describe('PayFlow Telegram approvals', () => {
  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await resetDemo(page);
    await page.close();
  });

  test('J5 a payout is approved through stored Telegram actions and audited with channel Telegram', { tag: '@flow' }, async ({ page, app }) => {
    await page.goto('/login');
    await clearTelegramOutbox(page);
    await telegram(page, DMITRY, { text: '/lang ru' });

    const list = await telegram(page, DMITRY, { text: '/approvals' });
    const listText = messageTexts(list);
    expect(listText).toContain('PO-2026-0089');
    expect(emojiIn(listText)).toEqual([]);
    const buttons = callbackButtons(list);
    for (const button of buttons) expect(button.data).toMatch(ACTION_RE);
    const approve = buttons.find((button) => /Согласовать/.test(button.text) && button.text.includes('PO-2026-0089'));
    const reject = buttons.find((button) => /Отклонить/.test(button.text) && button.text.includes('PO-2026-0089'));
    expect(approve, `approve button in ${JSON.stringify(buttons)}`).toBeTruthy();
    expect(reject).toBeTruthy();
    expect(buttons.some((button) => button.text.includes('PO-2026-0087')), 'on-hold payout has no approve button').toBeFalsy();
    expect(listText).toMatch(/http:\/\/(?:localhost|127\.0\.0\.1):\d+\/payouts/);

    const confirmation = await telegram(page, DMITRY, { callback_data: approve!.data });
    const confirmationText = messageTexts(confirmation);
    expect(confirmationText).toContain('PO-2026-0089');
    expect(confirmationText).toMatch(/7\s?200,00\s?\$/);
    expect(confirmationText).toContain('•••• 0047');
    const confirm = callbackButtons(confirmation).find((button) => /Подтвердить/.test(button.text));
    expect(confirm, `confirm button in ${JSON.stringify(callbackButtons(confirmation))}`).toBeTruthy();

    const result = messageTexts(await telegram(page, DMITRY, { callback_data: confirm!.data }));
    expect(result).toContain('Согласовано — 1/1');

    const replay = messageTexts(await telegram(page, DMITRY, { callback_data: confirm!.data }));
    expect(replay).not.toContain('Согласовано — 2/');

    const expired = messageTexts(await telegram(page, DMITRY, { callback_data: 'a:XXXXXXXX' }));
    expect(expired).toMatch(/\/approvals/);

    await pfLogin(page, PF.demoOwner);
    await app.setLocale(app.locale);
    await openPayout(page, 'PO-2026-0089');
    await expect(page.getByTestId('payout-status')).toHaveAttribute('data-status', 'approved');
    await expect(page.getByTestId('approvals')).toContainText('Telegram');
    await expectNoEmoji(page);
    await app.shot('j5-approved-via-telegram');

    await page.goto('/audit?action=payflow.payout.approved');
    await expect(page.locator('[data-test="audit-row"][data-action="payflow.payout.approved"]').first()).toBeVisible();
  });

  test('J5 a rejection reason is collected with a forced reply', { tag: '@flow' }, async ({ page, app }) => {
    await page.goto('/login');
    await telegram(page, DMITRY, { text: '/lang ru' });
    const list = await telegram(page, DMITRY, { text: '/approvals' });
    const reject = callbackButtons(list).find((button) => /Отклонить/.test(button.text) && button.text.includes('PO-2026-0092'));
    expect(reject, `reject button in ${JSON.stringify(callbackButtons(list))}`).toBeTruthy();

    const prompt = await telegram(page, DMITRY, { callback_data: reject!.data });
    const forceReply = prompt.find((call) => (call.payload.reply_markup as { force_reply?: boolean } | undefined)?.force_reply === true);
    expect(forceReply, 'ForceReply prompt').toBeTruthy();
    expect(String(forceReply!.payload.text)).toContain('PO-2026-0092');

    const outcome = messageTexts(await telegram(page, DMITRY, { text: 'wrong amount' }));
    expect(outcome).toContain('PO-2026-0092');

    await pfLogin(page, PF.demoOwner);
    await app.setLocale(app.locale);
    await openPayout(page, 'PO-2026-0092');
    await expect(page.getByTestId('payout-status')).toHaveAttribute('data-status', 'rejected');
    await expect(page.getByTestId('approvals')).toContainText('wrong amount');
  });
});
