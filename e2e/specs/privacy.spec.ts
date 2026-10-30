import { expect, test, uniqueId } from '../fixtures.ts';

const LEGAL_DOCS = ['terms', 'privacy', 'cookies', 'dpa', 'subprocessors', 'aup', 'ai', 'consent-ru'];
const RAW_KEY = /\b(?:ui|legal|notifications|emails|validation)\.[a-z_]+\.[a-z_.]+\b/;
const PLACEHOLDER = /\{\{[^}]*\}\}/;

function zipEntries(buffer: Buffer): string[] {
  const names: string[] = [];
  for (let offset = buffer.indexOf('PK\x01\x02'); offset !== -1; offset = buffer.indexOf('PK\x01\x02', offset + 4)) {
    const length = buffer.readUInt16LE(offset + 28);
    names.push(buffer.subarray(offset + 46, offset + 46 + length).toString('utf8'));
  }
  return names;
}

test.describe('legal pages', () => {
  test('every legal document renders with a toc, version and no unresolved placeholders', { tag: ['@visual', '@responsive'] }, async ({
    page,
    app,
  }) => {
    await page.goto('/');
    await app.setLocale(app.locale);
    await page.goto('/legal');
    const index = page.getByTestId('legal-index');
    await expect(index).toBeVisible();
    for (const key of LEGAL_DOCS) await expect(page.getByTestId(`legal-doc-${key}`)).toBeVisible();
    await app.shot('legal-index');

    for (const key of LEGAL_DOCS) {
      const response = await page.goto(`/legal/${key}`);
      expect(response?.status(), `/legal/${key}`).toBe(200);
      await expect(page.getByTestId('legal-title')).toBeVisible();
      await expect(page.getByTestId('legal-version')).toBeVisible();
      await expect(page.getByTestId('legal-template-banner')).toBeVisible();
      const body = page.getByTestId('legal-body');
      const text = await body.innerText();
      expect(text.length, `${key} body`).toBeGreaterThan(200);
      expect(text, `${key} unresolved placeholder`).not.toMatch(PLACEHOLDER);
      expect(text, `${key} leaked markdown marker`).not.toMatch(/template-notice|<!--|-->/);
      expect(await page.locator('main').last().innerText(), `${key} raw i18n key`).not.toMatch(RAW_KEY);
      if (app.locale === 'ru') expect(text, `${key} not in Russian`).toMatch(/[А-Яа-яЁё]{20,}|[А-Яа-яЁё]+\s+[А-Яа-яЁё]+\s+[А-Яа-яЁё]+/);
      if (key !== 'subprocessors') await expect(page.getByTestId('legal-toc').first()).toBeAttached();
      await app.expectNoHorizontalScroll();
    }
    await page.goto('/legal/privacy');
    await app.shot('legal-privacy');
  });

  test('unknown legal documents answer 404', { tag: '@flow' }, async ({ page }) => {
    const response = await page.goto('/legal/does-not-exist');
    expect(response?.status()).toBe(404);
  });

  test('footer links every legal document and the security page', { tag: '@visual' }, async ({ page }) => {
    await page.goto('/');
    const footer = page.locator('footer').last();
    for (const path of ['/legal/terms', '/legal/privacy', '/legal/cookies', '/legal/dpa', '/legal/subprocessors', '/legal/aup', '/legal/ai', '/security']) {
      await expect(footer.locator(`a[href$="${path}"], a[href*="${path}?"]`).first(), `footer link ${path}`).toBeAttached();
    }
  });

  test('cookie notice is non-blocking and stays dismissed', { tag: ['@visual', '@responsive'] }, async ({ page, app }) => {
    await page.goto('/');
    const notice = page.getByTestId('cookie-notice');
    await expect(notice).toBeVisible();
    await expect(notice.locator('a[href*="/legal/cookies"]')).toBeAttached();
    await expect(page.locator('main a, main button').first()).toBeEnabled();
    await app.shot('cookie-notice');
    await page.getByTestId('cookie-notice-dismiss').or(page.getByTestId('cookie-notice-close')).filter({ visible: true }).first().click();
    await expect(notice).toBeHidden();
    await page.reload();
    await page.waitForLoadState('networkidle');
    await expect(page.getByTestId('cookie-notice')).toHaveCount(0);
    const cookies = await page.context().cookies();
    const names = cookies.map((cookie) => cookie.name);
    expect(names.filter((name) => /^_ga|^_fbp|^_gid|amplitude|mixpanel|hotjar/i.test(name))).toEqual([]);
  });

  test('security.txt and robots.txt are published', { tag: '@flow' }, async ({ page }) => {
    const security = await page.request.get('/.well-known/security.txt');
    expect(security.status()).toBe(200);
    expect(security.headers()['content-type']).toContain('text/plain');
    const body = await security.text();
    expect(body).toMatch(/^Contact: /m);
    expect(body).toMatch(/^Expires: \d{4}-\d{2}-\d{2}T/m);
    expect(body).toMatch(/^Policy: /m);

    const robots = await page.request.get('/robots.txt');
    expect(robots.status()).toBe(200);
    const rules = await robots.text();
    expect(rules).toMatch(/User-agent: \*/i);
    expect(rules).toMatch(/Disallow: \/(?:settings|dashboard|internal)/);
  });
});

test.describe('consent at registration', () => {
  test('registration requires the terms checkbox and, for Russian, the 152-FZ consent', { tag: '@flow' }, async ({ page, app }, testInfo) => {
    await page.goto('/');
    await app.setLocale(app.locale);
    await page.goto('/register');
    const terms = page.getByTestId('register-accept-terms');
    await expect(terms).toHaveAttribute('aria-checked', 'false');
    const consentRu = page.getByTestId('register-accept-consent-ru');
    if (app.locale === 'ru') await expect(consentRu).toHaveAttribute('aria-checked', 'false');
    else await expect(consentRu).toHaveCount(0);

    const id = uniqueId(testInfo);
    await page.locator('input[name="name"]').fill(`E2E consent ${id}`);
    await page.locator('input[name="email"]').fill(`e2e.consent.${id}@${app.config.slug}.test`);
    await page.locator('input[name="password"]').fill(`E2e!${id}Pw9`);
    await page.locator('input[name="password_confirmation"]').fill(`E2e!${id}Pw9`);
    await page.getByTestId('register-user-button').click();
    await expect(page).toHaveURL(/\/register(?:[?#]|$)/);
    await expect(page.getByTestId('register-consents').locator('[role="alert"], .text-destructive, [data-test="input-error"]').first()).toBeVisible();
    await app.shot('register-consent-required');

    await app.acceptRegistrationConsents();
    await page.getByTestId('register-user-button').click();
    await expect(page).not.toHaveURL(/\/register(?:[?#]|$)/, { timeout: 20_000 });
    await app.verifyEmailIfRequired(`e2e.consent.${id}@${app.config.slug}.test`);
    await app.completeOnboarding(`E2E Consent ${id}`);

    await page.goto('/settings/privacy');
    const acceptances = page.getByTestId('privacy-acceptances');
    await expect(acceptances).toBeVisible();
    const expected = app.locale === 'ru' ? ['terms', 'privacy', 'consent-ru', 'dpa'] : ['terms', 'privacy', 'dpa'];
    const text = await acceptances.innerText();
    expect(text.length).toBeGreaterThan(0);
    const rows = await acceptances.locator('li, tr').count();
    expect(rows, `acceptances: ${text}`).toBeGreaterThanOrEqual(expected.length);
  });
});

test.describe('settings → privacy', () => {
  test('renders in both themes and locales without raw keys or overflow', { tag: ['@visual', '@responsive'] }, async ({ page, app, owner }) => {
    expect(owner.email).toBeTruthy();
    await page.goto('/settings/privacy');
    await expect(page.getByTestId('privacy-export')).toBeVisible();
    await expect(page.getByTestId('privacy-account-delete')).toBeVisible();
    await expect(page.getByTestId('privacy-workspace-settings')).toBeVisible();
    expect(await page.locator('main').last().innerText()).not.toMatch(RAW_KEY);
    await app.expectNoHorizontalScroll();
    await app.axe('settings-privacy', { soft: true });
    await app.shot('settings-privacy');
  });

  test('personal export: queued, e-mailed signed link, ZIP with JSON and a manifest', { tag: '@flow' }, async ({ page, app, owner }) => {
    await page.goto('/settings/privacy');
    await page.getByTestId('privacy-export-request').click();
    const link = await app.mailpit.waitForLink(owner.email, /\/settings\/privacy\/exports\/\d+\/download/, 90_000);
    expect(link).toMatch(/[?&]signature=/);
    expect(link).toMatch(/[?&]expires=/);

    const download = await page.request.get(link);
    expect(download.status()).toBe(200);
    expect(download.headers()['cache-control'] ?? '').toContain('no-store');
    const zip = Buffer.from(await download.body());
    expect(zip.subarray(0, 2).toString()).toBe('PK');
    const entries = zipEntries(zip);
    expect(entries).toContain('manifest.json');
    expect(entries.filter((entry) => entry.endsWith('.json')).length).toBeGreaterThan(1);
    const raw = zip.toString('latin1');
    expect(raw).not.toMatch(/"password"\s*:/);
    expect(raw).not.toMatch(/two_factor_secret|remember_token/);

    const tampered = await page.request.get(link.replace(/signature=[^&]+/, 'signature=deadbeef'));
    expect(tampered.status()).toBe(403);

    await page.goto('/settings/privacy');
    await expect(page.getByTestId('export-list')).toBeVisible();
    await app.shot('settings-privacy-export');
  });

  test('another account cannot download the export through the same signed link', { tag: '@flow' }, async ({ page, app, owner, openSession }) => {
    await page.goto('/settings/privacy');
    await page.getByTestId('privacy-export-request').click();
    const link = await app.mailpit.waitForLink(owner.email, /\/settings\/privacy\/exports\/\d+\/download/, 90_000);
    const stranger = await openSession();
    await stranger.app.registerOwner('stranger');
    const response = await stranger.page.request.get(link);
    expect([403, 404]).toContain(response.status());
  });

  test('account deletion is scheduled after re-authentication and can be cancelled', { tag: '@flow' }, async ({ page, app, owner }) => {
    await page.goto('/settings/privacy');
    await page.getByTestId('account-delete-open').click();
    const dialog = page.getByRole('alertdialog').or(page.getByRole('dialog')).first();
    await dialog.getByTestId('account-delete-password').locator('input').or(dialog.locator('input[type="password"]')).first().fill('wrong-password');
    await dialog.getByTestId('confirm-dialog-confirm').click();
    await expect(page.getByTestId('account-deletion-scheduled')).toHaveCount(0);

    await dialog.locator('input[type="password"]').first().fill(owner.password);
    await dialog.getByTestId('confirm-dialog-confirm').click();
    await expect(page.getByTestId('account-deletion-scheduled')).toBeVisible();
    await expect(page.getByTestId('account-deletion-countdown')).toBeVisible();
    await app.shot('settings-privacy-deletion-scheduled');

    await page.getByTestId('account-deletion-cancel').click();
    await expect(page.getByTestId('account-deletion-scheduled')).toHaveCount(0);
    await expect(page.getByTestId('account-delete-open')).toBeVisible();
  });

  test('owners control external AI providers; the setting persists', { tag: '@flow' }, async ({ page, app, owner }) => {
    expect(owner.workspace).toBeTruthy();
    await page.goto('/settings/privacy');
    const toggle = page.getByTestId('ai-external-switch');
    await expect(toggle).toHaveAttribute('aria-checked', 'false');
    await toggle.click();
    const saved = page.waitForResponse((response) => response.request().method() !== 'GET' && new URL(response.url()).pathname === '/settings/privacy/workspace');
    await page.getByTestId('privacy-workspace-save').click();
    expect((await saved).status()).toBeLessThan(400);
    await expect(page.getByTestId('privacy-workspace-save')).toBeDisabled();
    await page.reload();
    await expect(page.getByTestId('ai-external-switch')).toHaveAttribute('aria-checked', 'true');
    await app.shot('settings-privacy-ai-external');
  });
});
