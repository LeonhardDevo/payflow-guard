import { chooseOption, clearTelegramOutbox, expect, expectNoEmoji, messageTexts, money, PF, pfLogin, resetDemo, telegramOutbox, test } from '../support/payflow.ts';

const amountRe = (major: string, cents: string) => new RegExp(`${major.replace(/,/g, '[,\\s]?')}[.,]${cents}`);

test.describe.configure({ mode: 'serial' });

test.describe('PayFlow invoices', () => {
  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await resetDemo(page);
    await page.close();
  });

  test('J1 invoice is created, e-mailed, opened publicly and paid', { tag: '@flow' }, async ({ page, app, mailpit, openSession }) => {
    const started = new Date();
    await pfLogin(page, PF.finance);
    await clearTelegramOutbox(page);
    await app.setLocale(app.locale);

    await page.goto('/invoices/create');
    await chooseOption(page, 'inv-client', 'QA Client');
    const lines = page.getByTestId('invoice-line');
    await lines.nth(0).locator('input').nth(0).fill('Discovery workshop');
    await lines.nth(0).locator('input').nth(1).fill('1');
    await lines.nth(0).locator('input').nth(2).fill('1200.00');
    await page.getByTestId('add-line').click();
    await lines.nth(1).locator('input').nth(0).fill('Prototype');
    await lines.nth(1).locator('input').nth(1).fill('10');
    await lines.nth(1).locator('input').nth(2).fill('150.00');
    await lines.nth(1).locator('input').nth(3).fill('20');

    await expect(page.getByTestId('preview-subtotal')).toHaveText(amountRe('2,700', '00'));
    await expect(page.getByTestId('preview-tax')).toHaveText(amountRe('300', '00'));
    await expect(page.getByTestId('preview-total')).toHaveText(amountRe('3,000', '00'));
    await app.shot('j1-create');

    await page.getByTestId('invoice-save-send').click();
    await expect(page).toHaveURL(/\/invoices\/\d+$/);
    await expect(page.getByTestId('invoice-status')).toHaveAttribute('data-status', /open|due_soon/);
    const number = (await page.title()).split(' ')[0] ?? '';
    expect(number).toMatch(/^[A-Z0-9-]+-\d{4}-\d{4}$/);
    const timeline = page.getByTestId('pf-timeline');
    await expect(timeline.locator('[data-type="issued"]')).toHaveCount(1);
    await expect(timeline.locator('[data-type="sent"]')).toHaveCount(1);

    const mail = await mailpit.waitForMail({ to: 'qa-client@e2e.example', subject: new RegExp(number), since: started, timeoutMs: 60_000 });
    const attachments = (mail as unknown as { Attachments?: Array<{ FileName: string }> }).Attachments ?? [];
    expect(attachments.some((file) => /\.pdf$/i.test(file.FileName)), 'PDF attached').toBeTruthy();
    const link = (mail.HTML + mail.Text).match(/https?:\/\/[^\s"'<>]+\/i\/[A-Za-z0-9]+/)?.[0];
    expect(link, 'public invoice link in e-mail').toBeTruthy();
    expect(mail.HTML + mail.Text).toMatch(/\/legal\/reminder-recipients/);

    const visitor = await openSession();
    await visitor.page.goto(link!);
    await expect(visitor.page.getByTestId('public-invoice')).toBeVisible();
    await expect(visitor.page.getByTestId('public-footer')).toBeVisible();
    await expect(visitor.page.getByTestId('public-balance')).toHaveText(amountRe('3,000', '00'));
    await expectNoEmoji(visitor.page);
    await visitor.app.axe('j1-public-invoice');
    await visitor.app.shot('j1-public-invoice');
    await visitor.page.getByTestId('public-pay').click();
    await expect(visitor.page.getByTestId('payment-thanks')).toHaveAttribute('data-status', 'paid', { timeout: 60_000 });

    await page.reload();
    await expect(page.getByTestId('invoice-status')).toHaveAttribute('data-status', 'paid', { timeout: 30_000 });
    await expect(page.getByTestId('payment-row').first()).toHaveAttribute('data-status', 'succeeded');
    await expect(timeline.locator('[data-type="payment_received"]')).toHaveCount(1);
    await expectNoEmoji(page);
    await app.shot('j1-paid');

    const receipts = await mailpit.searchSince('qa-client@e2e.example', started);
    expect(receipts.filter((message) => message.Subject.includes(number)).length, 'invoice and receipt e-mails').toBeGreaterThanOrEqual(2);

    await expect(async () => {
      const calls = (await telegramOutbox(page)).filter((call) => String(call.payload.chat_id) === '700000100');
      const text = messageTexts(calls);
      expect(text).toMatch(/Payment received|Поступила оплата/);
      expect(text).toContain(number);
    }).toPass({ timeout: 30_000, intervals: [1_000, 2_000] });
  });

  test('J1b partial payment is recorded and reversed', { tag: '@flow' }, async ({ page, app }) => {
    await pfLogin(page, PF.demoOwner);
    await app.setLocale(app.locale);
    await page.goto('/invoices?q=NS-2026-0151');
    await page.locator('[data-test="invoice-row"][data-number="NS-2026-0151"] a').first().click();
    await expect(page).toHaveURL(/\/invoices\/\d+$/);

    await page.getByTestId('invoice-record-payment').click();
    await page.getByTestId('payment-amount').fill('1000.00');
    await page.getByTestId('payment-submit').click();
    await expect(page.getByTestId('invoice-status')).toHaveAttribute('data-status', /partially_paid|overdue/);
    await expect(page.getByTestId('invoice-balance')).toHaveText(amountRe('1,350', '00'));

    await page.getByRole('button', { name: /reverse|отменить/i }).first().click();
    const dialog = page.getByRole('dialog');
    await dialog.locator('input').fill('Recorded by mistake');
    await dialog.getByRole('button', { name: /reverse|отменить/i }).last().click();
    await expect(page.getByTestId('invoice-balance')).toHaveText(amountRe('2,350', '00'));
    await expect(page.getByTestId('payment-row').first()).toHaveAttribute('data-status', 'reversed');
  });

  test('J8 late payer gets a final tone, risk factors and the agreed late fee', { tag: '@flow' }, async ({ page, app }) => {
    await pfLogin(page, PF.demoOwner);
    await app.setLocale(app.locale);

    await page.goto('/clients');
    await page.getByTestId('class-filter-chronic').click();
    await expect(page.getByTestId('client-row').filter({ hasText: 'Helios' })).toBeVisible();

    await page.goto('/invoices?q=NS-2026-0118');
    await page.locator('[data-test="invoice-row"][data-number="NS-2026-0118"] a').first().click();
    await expect(page.locator('[data-test="tone-chip"][data-tone="final"]').first()).toBeVisible();
    await expect(page.getByTestId('tone-reasons').locator('li').first()).toBeVisible();
    await expect(page.getByTestId('risk-badge').first()).toHaveAttribute('data-band', 'high');
    await expect(page.getByTestId('risk-factors').locator('li').first()).toBeVisible();
    expect(await money(page.getByTestId('late-fee-amount'))).toMatch(/260[.,]40/);

    await page.getByTestId('invoice-remind').click();
    await expect(page.getByTestId('reminder-subject')).toBeVisible();
    await expect(page.getByTestId('reminder-body')).toContainText(/reminder-recipients|already paid|уже оплатили/i);
    await page.keyboard.press('Escape');

    await page.getByTestId('apply-late-fee').click();
    await page.getByTestId('late-fee-confirm').click();
    await expect(page.getByTestId('invoice-total')).toHaveText(amountRe('12,660', '40'));
    await app.shot('j8-late-fee');

    await page.goto('/dashboard');
    await expect(page.getByTestId('dashboard-stats')).toBeVisible();
    await expect(page.getByTestId('attention').locator('li').first()).toHaveAttribute('data-number', 'NS-2026-0118');
  });
});
