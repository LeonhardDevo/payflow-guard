import { expect, type Locator, type Page } from '@playwright/test';
import { test as base, type Session } from '../fixtures.ts';
import { loadConfig } from './config.ts';
import { overflowingControls, pageEmoji, rawKeysIn, watchConsole } from './guards.ts';
import { xsrfToken } from './http.ts';
import type { OutboundCall } from './telegram.ts';

const cfg = loadConfig();

interface PayflowFixtures {
  consoleGuard: string[];
}

export const test = base.extend<PayflowFixtures>({
  consoleGuard: [
    async ({ context }, use, testInfo) => {
      const problems: string[] = [];
      watchConsole(context, problems);
      await use(problems);
      if (problems.length > 0) {
        await testInfo.attach('console-errors.txt', { body: problems.join('\n'), contentType: 'text/plain' });
        throw new Error(`console errors (${problems.length}):\n  ${problems.slice(0, 10).join('\n  ')}`);
      }
    },
    { auto: true },
  ],

  openSession: async ({ openSession, consoleGuard }, use) => {
    await use(async (): Promise<Session> => {
      const session = await openSession();
      watchConsole(session.context, consoleGuard);
      return session;
    });
  },
});

export { expect };

export async function expectNoEmoji(page: Page): Promise<void> {
  expect(await pageEmoji(page), `emoji rendered on ${page.url()}`).toEqual([]);
}

export async function expectCleanPage(page: Page): Promise<void> {
  await expectNoEmoji(page);
  const text = await page.evaluate(() => document.body?.innerText ?? '');
  expect(rawKeysIn(text), `untranslated keys on ${page.url()}`).toEqual([]);
  expect(await overflowingControls(page), `clipped controls on ${page.url()}`).toEqual([]);
}

export function clockNoon(date = new Date()): string {
  const day = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Riga', year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
  const probe = new Date(`${day}T12:00:00Z`);
  const riga = new Date(probe.toLocaleString('en-US', { timeZone: 'Europe/Riga' }));
  const utc = new Date(probe.toLocaleString('en-US', { timeZone: 'UTC' }));
  const offsetMinutes = Math.round((riga.getTime() - utc.getTime()) / 60_000);
  const sign = offsetMinutes >= 0 ? '+' : '-';
  const abs = Math.abs(offsetMinutes);
  return `${day}T12:00:00${sign}${String(Math.floor(abs / 60)).padStart(2, '0')}:${String(abs % 60).padStart(2, '0')}`;
}

export function rigaDate(offsetDays: number, date = new Date()): string {
  const shifted = new Date(date.getTime() + offsetDays * 86_400_000);
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Riga', year: 'numeric', month: '2-digit', day: '2-digit' }).format(shifted);
}

export interface TelegramSender {
  id: number;
  language: 'en' | 'ru';
}

export async function telegram(page: Page, from: TelegramSender, input: { text: string } | { callback_data: string }): Promise<OutboundCall[]> {
  const response = await page.request.post(`${cfg.workerURL}/dev/telegram/update`, {
    data: { from: { id: from.id, first_name: 'E2E', language_code: from.language }, ...input },
  });
  expect(response.status(), `dev/telegram/update: ${await response.text()}`).toBe(200);
  return ((await response.json()) as { outbound: OutboundCall[] }).outbound;
}

export async function telegramOutbox(page: Page): Promise<OutboundCall[]> {
  const response = await page.request.get(`${cfg.workerURL}/dev/telegram/outbox`);
  expect(response.status()).toBe(200);
  return ((await response.json()) as { outbound: OutboundCall[] }).outbound;
}

export async function clearTelegramOutbox(page: Page): Promise<void> {
  const response = await page.request.delete(`${cfg.workerURL}/dev/telegram/outbox`);
  expect(response.status()).toBeLessThan(300);
}

export function callbackButtons(calls: readonly OutboundCall[]): Array<{ text: string; data: string }> {
  const buttons: Array<{ text: string; data: string }> = [];
  for (const call of calls) {
    const markup = call.payload.reply_markup as { inline_keyboard?: Array<Array<{ text: string; callback_data?: string }>> } | undefined;
    for (const row of markup?.inline_keyboard ?? []) {
      for (const button of row) if (button.callback_data) buttons.push({ text: button.text, data: button.callback_data });
    }
  }
  return buttons;
}

export function messageTexts(calls: readonly OutboundCall[]): string {
  return calls
    .filter((call) => ['sendMessage', 'editMessageText', 'answerCallbackQuery'].includes(call.method))
    .map((call) => String(call.payload.text ?? ''))
    .join('\n');
}

export const PF = {
  owner: 'e2e@payflow.test',
  approver: 'e2e-approver@payflow.test',
  admin: 'e2e-admin@payflow.test',
  finance: 'e2e-finance@payflow.test',
  newApprover: 'e2e-new-approver@payflow.test',
  solo: 'solo@payflow.test',
  demoOwner: 'demo@payflow.test',
  demoFinance: 'finance@payflow.test',
} as const;

export async function resetDemo(page: Page): Promise<void> {
  if (!page.url().startsWith('http')) await page.goto(`${cfg.baseURL}/login`);
  const response = await page.request.post(`${cfg.baseURL}/dev/payflow/reset-demo`, {
    headers: { 'X-XSRF-TOKEN': await xsrfToken(page), 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    timeout: 180_000,
  });
  expect(response.status(), `reset-demo: ${await response.text()}`).toBe(200);
}

const usedCodes = new Map<string, string>();

export async function totp(page: Page, email: string): Promise<string> {
  for (let attempt = 0; attempt < 6; attempt++) {
    const response = await page.request.get(`${cfg.baseURL}/dev/payflow/totp?email=${encodeURIComponent(email)}`);
    expect(response.status(), `totp for ${email}`).toBe(200);
    const { code } = (await response.json()) as { code: string };
    const remaining = 30 - (Math.floor(Date.now() / 1000) % 30);
    if (remaining > 4 && usedCodes.get(email) !== code) {
      usedCodes.set(email, code);
      return code;
    }
    await page.waitForTimeout((remaining + 1) * 1000);
  }
  throw new Error(`could not obtain a fresh TOTP code for ${email}`);
}

export async function acceptLegalIfPrompted(page: Page): Promise<void> {
  if (!/\/legal\/accept/.test(page.url())) return;
  await page.getByRole('checkbox').first().click();
  await page.getByRole('button', { name: /accept|принять/i }).click();
  await expect(page).not.toHaveURL(/\/legal\/accept/, { timeout: 15_000 });
}

export async function pfLogin(page: Page, email: string): Promise<void> {
  await page.goto('/login');
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(cfg.credentials.password);
  await page.getByTestId('login-button').click();
  await page.waitForURL((url) => !/\/login(?:[?#]|$)/.test(url.pathname), { timeout: 20_000 });
  if (/two-factor-challenge/.test(page.url())) {
    const code = await totp(page, email);
    const input = page.locator('input[autocomplete="one-time-code"], [data-input-otp="true"]').first();
    await input.click();
    await page.keyboard.type(code);
    await page.waitForURL((url) => !/two-factor-challenge/.test(url.pathname), { timeout: 20_000 }).catch(async () => {
      await page.keyboard.press('Enter');
      await page.waitForURL((url) => !/two-factor-challenge/.test(url.pathname), { timeout: 20_000 });
    });
  }
  await acceptLegalIfPrompted(page);
}

export async function chooseOption(page: Page, triggerId: string, label: string | RegExp): Promise<void> {
  await page.locator(`#${triggerId}`).click();
  const search = page.getByRole('searchbox');
  if (typeof label === 'string') await search.fill(label);
  await page.getByRole('option', { name: label }).first().click();
}

export async function completeStepUp(page: Page, email: string): Promise<void> {
  const dialog = page.getByTestId('step-up-dialog');
  await expect(dialog).toBeVisible();
  const totpChoice = dialog.getByTestId('step-up-totp');
  if (await totpChoice.isVisible()) await totpChoice.click();
  const code = dialog.getByTestId('step-up-code');
  if (await code.isVisible()) await code.fill(await totp(page, email));
  else await dialog.getByTestId('step-up-password').fill(cfg.credentials.password);
  await dialog.getByTestId('step-up-confirm').click();
  await expect(dialog).toBeHidden({ timeout: 20_000 });
}

export async function runJob(page: Page, job: string, now?: string): Promise<void> {
  const response = await page.request.post(`${cfg.workerURL}/dev/jobs/run`, { data: { job, ...(now ? { now } : {}) } });
  expect(response.status(), `run ${job}: ${await response.text()}`).toBeLessThan(300);
}

export async function drain(page: Page, queues: string[], options: { promoteDelayed?: boolean; timeoutMs?: number } = {}): Promise<void> {
  const response = await page.request.post(`${cfg.workerURL}/dev/jobs/drain`, {
    data: { queues, promote_delayed: options.promoteDelayed ?? false, timeout_ms: options.timeoutMs ?? 10_000 },
    timeout: (options.timeoutMs ?? 10_000) + 30_000,
  });
  expect(response.status(), `drain ${queues.join(',')}: ${await response.text()}`).toBeLessThan(300);
}

export function money(locator: Locator): Promise<string> {
  return locator.innerText().then((text) => text.replace(/[\s  ]/g, ' ').trim());
}
