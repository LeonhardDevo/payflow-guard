import { expect, expectCleanPage, PF, pfLogin, resetDemo, test } from '../support/payflow.ts';

test.describe.configure({ mode: 'serial', timeout: 420_000 });

test.describe('PayFlow public pages and accessibility', () => {
  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await resetDemo(page);
    await page.close();
  });

  test('J12 recipient reports payment and stops automated reminders', { tag: '@flow' }, async ({ page, app, openSession }) => {
    await pfLogin(page, PF.demoOwner);
    await app.setLocale(app.locale);
    await page.goto('/invoices?q=NS-2026-0151');
    await page.locator('[data-test="invoice-row"][data-number="NS-2026-0151"] a').first().click();
    await expect(page).toHaveURL(/\/invoices\/\d+$/);
    const invoiceUrl = page.url();
    const publicUrl = await page.locator('a[href*="/i/"]').first().getAttribute('href');
    expect(publicUrl).toBeTruthy();

    const visitor = await openSession();
    await visitor.page.goto(publicUrl!);
    await visitor.page.getByTestId('choices-link').click();
    await expect(visitor.page).toHaveURL(/\/i\/[A-Za-z0-9]+\/reminders/);
    await visitor.app.axe('j12-choices');

    await visitor.page.locator('#rc-ref').fill('BANK-REF-77');
    await visitor.page.getByTestId('report-paid').click();
    await expect(visitor.page.getByTestId('reported-paid')).toBeVisible();

    await visitor.page.getByTestId('stop-reminders').click();
    await expect(visitor.page.getByTestId('opted-out')).toBeVisible();
    await visitor.app.shot('j12-choices-done');

    await page.goto(invoiceUrl);
    await expect(page.getByTestId('client-reported-paid')).toBeVisible();

    const response = await visitor.page.request.post(`${new URL(publicUrl!).pathname}/reminders/one-click`, { form: { 'List-Unsubscribe': 'One-Click' } });
    expect(response.status()).toBe(200);
  });

  test('J10 key pages pass axe, show no raw keys or emoji and never scroll sideways', { tag: ['@visual', '@responsive'] }, async ({ page, app, openSession }) => {
    test.setTimeout(900_000);
    await pfLogin(page, PF.demoOwner);
    await app.setLocale(app.locale);

    const detail = async (list: string, selector: string): Promise<string> => {
      await page.goto(list);
      await page.locator(selector).first().click();
      await page.waitForURL((url) => url.pathname !== new URL(list, app.config.baseURL).pathname);
      return new URL(page.url()).pathname;
    };
    const invoice = await detail('/invoices?q=NS-2026-0118', '[data-test="invoice-row"][data-number="NS-2026-0118"] a');
    const publicUrl = await page.locator('a[href*="/i/"]').first().getAttribute('href');
    const client = await detail('/clients?q=Helios', '[data-test="client-row"] a');
    const payout = await detail('/payouts?tab=all&q=PO-2026-0092', '[data-test="payout-row"][data-number="PO-2026-0092"]');
    const vendor = await detail('/vendors', 'a:has-text("PixelForge Studio")');
    const review = await page.getByTestId('vendor-pending-change').getByRole('link').first().getAttribute('href');

    const pages: Array<[string, string]> = [
      ['dashboard', '/dashboard'],
      ['invoices', '/invoices'],
      ['invoice-show', invoice],
      ['clients', '/clients'],
      ['client-show', client],
      ['reminders', '/reminders'],
      ['analytics', '/analytics'],
      ['guard', '/guard'],
      ['vendors', '/vendors'],
      ['vendor-show', vendor],
      ['bank-change-review', new URL(review!, app.config.baseURL).pathname],
      ['payouts', '/payouts?tab=all'],
      ['payout-show', payout],
      ['settings-collections', '/settings/collections'],
      ['settings-payments', '/settings/payments'],
      ['settings-guard', '/settings/guard'],
      ['settings-notifications', '/settings/notifications'],
      ['assistant', '/assistant'],
      ['audit', '/audit'],
    ];
    const screenshots = new Set(['dashboard', 'invoice-show', 'bank-change-review', 'payout-show']);

    const widths = app.testInfo.project.name.includes('mobile') ? [0] : [0, 375];
    for (const width of widths) {
      if (width) await page.setViewportSize({ width, height: 812 });
      for (const [name, path] of pages) {
        await page.goto(path);
        await expect(page.locator('main, #main').first()).toBeVisible();
        await page.waitForLoadState('networkidle');
        await app.expectNoHorizontalScroll();
        await expectCleanPage(page);
        if (!width) await app.axe(`j10-${name}`);
        if (screenshots.has(name)) await app.shot(width ? `j10-${name}-${width}` : `j10-${name}`);
      }
    }

    const visitor = await openSession();
    const publicPath = new URL(publicUrl!, app.config.baseURL).pathname;
    for (const [name, path] of [
      ['public-invoice', publicPath],
      ['public-choices', `${publicPath}/reminders`],
    ] as const) {
      await visitor.page.goto(path);
      await visitor.page.waitForLoadState('networkidle');
      await visitor.app.expectNoHorizontalScroll();
      await expectCleanPage(visitor.page);
      await visitor.app.axe(`j10-${name}`);
      await visitor.app.shot(`j10-${name}`);
    }
  });
});
