import type { Page } from '@playwright/test';
import { chooseOption, clockNoon, drain, expect, expectNoEmoji, PF, pfLogin, resetDemo, rigaDate, runJob, test } from '../support/payflow.ts';

test.describe.configure({ mode: 'serial', timeout: 300_000 });

const REMINDER_QUEUES = ['pf-scheduler', 'pf-reminders', 'emails'];

async function planAndDrain(page: Page): Promise<void> {
  await runJob(page, 'reminders:plan-all', clockNoon());
  await drain(page, REMINDER_QUEUES, { promoteDelayed: true, timeoutMs: 25_000 });
}

async function createOverdueInvoice(page: Page, client: string, description: string): Promise<{ url: string; number: string }> {
  await page.goto('/invoices/create');
  await chooseOption(page, 'inv-client', client);
  await page.locator('#inv-issue').fill(rigaDate(-20));
  await page.locator('#inv-due').fill(rigaDate(-6));
  const line = page.getByTestId('invoice-line').first();
  await line.locator('input').nth(0).fill(description);
  await line.locator('input').nth(1).fill('1');
  await line.locator('input').nth(2).fill('480.00');
  await page.getByTestId('invoice-save-mark-sent').click();
  await expect(page).toHaveURL(/\/invoices\/\d+$/);
  await expect(page.getByTestId('invoice-status')).toHaveAttribute('data-status', 'overdue');
  const number = (await page.title()).split(' ')[0] ?? '';
  expect(number).toMatch(/^[A-Z0-9-]+-\d{4}-\d{4}$/);
  return { url: new URL(page.url()).pathname, number };
}

test.describe('PayFlow automated reminders and late-payer analytics', () => {
  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await resetDemo(page);
    await page.close();
  });

  test('J2 the worker plans and sends a reminder in the engine tone, then stops after payment', { tag: '@flow' }, async ({ page, app, mailpit }) => {
    const started = new Date();
    await pfLogin(page, PF.finance);
    await app.setLocale(app.locale);

    const invoice = await createOverdueInvoice(page, 'Тестовый клиент', 'Сопровождение — октябрь');
    await planAndDrain(page);

    const mail = await mailpit.waitForMail({
      to: 'ru-client@e2e.example',
      subject: new RegExp(`${invoice.number}.*6 дней|6 дней.*${invoice.number}`),
      since: started,
      timeoutMs: 60_000,
    });
    expect(mail.Subject).toMatch(/^Напоминание/);
    const headers = await (await fetch(`${app.config.mailpitURL}/api/v1/message/${mail.ID}/headers`)).json() as Record<string, string[]>;
    expect(headers['List-Unsubscribe']?.[0] ?? '', 'List-Unsubscribe header').toMatch(/^<https?:\/\/[^>]+>/);
    expect(headers['List-Unsubscribe-Post']?.[0] ?? '').toBe('List-Unsubscribe=One-Click');
    expect(mail.HTML + mail.Text).toMatch(/\/legal\/reminder-recipients/);

    await page.goto('/reminders?tab=sent');
    const sent = page.locator('[data-test="reminder-row"][data-status="sent"]').filter({ hasText: invoice.number });
    await expect(sent).toHaveCount(1);
    await expect(sent).toHaveAttribute('data-tone', /friendly|neutral/);
    await expectNoEmoji(page);
    await app.shot('j2-reminders-sent');

    await page.goto(invoice.url);
    await page.getByTestId('invoice-record-payment').click();
    await page.getByTestId('payment-submit').click();
    await expect(page.getByTestId('invoice-status')).toHaveAttribute('data-status', 'paid');

    const paidAt = new Date();
    await planAndDrain(page);
    await page.waitForTimeout(1_500);
    const after = (await mailpit.searchSince('ru-client@e2e.example', paidAt)).filter((message) => message.Subject.includes(invoice.number) && /^Напоминание/.test(message.Subject));
    expect(after, 'no reminder after full payment').toEqual([]);

    await page.goto('/reminders?tab=upcoming');
    await expect(page.locator('[data-test="reminder-row"][data-status="scheduled"]').filter({ hasText: invoice.number })).toHaveCount(0);
  });

  test('J8 overdue client analytics show the chronic late payer', { tag: '@flow' }, async ({ page, app }) => {
    await pfLogin(page, PF.demoOwner);
    await app.setLocale(app.locale);

    await page.goto('/clients');
    await page.getByTestId('class-filter-chronic').click();
    const helios = page.getByTestId('client-row').filter({ hasText: 'Helios Media Group' });
    await expect(helios).toHaveCount(1);
    await expect(page.getByTestId('client-row').filter({ hasText: 'Brightpath' })).toHaveCount(0);

    await page.goto('/analytics');
    await expect(page.getByTestId('analytics-kpis')).toBeVisible();
    const title = app.locale === 'ru' ? 'Кто платит с опозданием' : 'Late payers';
    const panel = page.getByRole('heading', { name: title, exact: true }).locator('xpath=ancestor::section[1]');
    await panel.getByRole('radio', { name: app.locale === 'ru' ? 'Таблица' : 'Table' }).click();
    const row = panel.locator('tbody tr').filter({ hasText: 'Helios Media Group' });
    await expect(row).toBeVisible();
    await expect(row).toContainText(/18[.,]4/);
    await expect(panel.locator('tbody tr').first()).toContainText('Helios Media Group');
    await expectNoEmoji(page);
    await app.axe('j8-analytics');
    await app.shot('j8-analytics');

    await page.goto('/invoices?q=NS-2026-0118');
    await page.locator('[data-test="invoice-row"][data-number="NS-2026-0118"] a').first().click();
    await expect(page.locator('[data-test="tone-chip"][data-tone="final"]').first()).toBeVisible();
    await expect(page.getByTestId('risk-badge').first()).toHaveAttribute('data-band', 'high');
  });
});
