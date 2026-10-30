import { expect, test, type AppLocale, type AppTheme } from '../fixtures.ts';

const other = (locale: AppLocale): AppLocale => (locale === 'en' ? 'ru' : 'en');
const opposite = (theme: AppTheme): AppTheme => (theme === 'light' ? 'dark' : 'light');

declare global {
  interface Window {
    __themeAtFirstPaint?: 'dark' | 'light';
  }
}

test.describe('preferences', () => {
  test('guest locale follows the browser Accept-Language', { tag: '@visual' }, async ({ page, app }) => {
    await page.goto('/');
    await app.expectLocale(app.locale);
  });

  test('locale switcher changes the language and it persists across reloads', { tag: '@visual' }, async ({ page, app }) => {
    const target = other(app.locale);
    await page.goto('/');
    await app.switchLocaleViaUi(target);

    await page.reload();
    await app.expectLocale(target);
    await page.goto('/pricing');
    await app.expectLocale(target);
    await app.shot(`locale-switched-${target}`);
  });

  test('theme toggle switches theme, persists across reloads and is applied before first paint', { tag: '@visual' }, async ({
    page,
    app,
  }) => {
    const target = opposite(app.theme);
    await page.goto('/');
    await app.expectTheme(app.theme);

    await page.getByTestId('theme-toggle').click();
    await page.getByTestId(`theme-option-${target}`).click();
    await app.expectTheme(target);
    expect(await page.evaluate(() => localStorage.getItem('appearance'))).toBe(target);

    await page.addInitScript(() => {
      const observer = new MutationObserver(() => {
        if (document.body) {
          window.__themeAtFirstPaint = document.documentElement.classList.contains('dark') ? 'dark' : 'light';
          observer.disconnect();
        }
      });
      observer.observe(document, { childList: true, subtree: true });
    });
    await page.reload();
    await app.expectTheme(target);
    expect(await page.evaluate(() => window.__themeAtFirstPaint), 'theme flashed on load (FOUC)').toBe(target);
    await app.shot(`theme-switched-${target}`);
  });

  test('a signed-in user keeps the chosen language on the next login', { tag: '@flow' }, async ({ page, app, owner, openSession }) => {
    const target = other(app.locale);
    await page.goto('/dashboard');
    await app.switchLocaleViaUi(target);

    const second = await openSession();
    await second.app.login(owner.email, owner.password);
    await second.page.goto('/dashboard');
    await second.app.expectLocale(target);
  });
});
