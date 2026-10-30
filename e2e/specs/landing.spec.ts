import { expect, test } from '../fixtures.ts';

test.describe('marketing', () => {
  test('landing renders in the project locale and theme with zero external requests', { tag: ['@visual', '@responsive'] }, async ({
    page,
    app,
    networkGuard,
  }) => {
    await page.goto('/');
    await app.setLocale(app.locale);
    await app.expectTheme(app.theme);
    await expect(page.getByTestId('landing-hero')).toBeVisible();
    await expect(page.getByTestId('cta-register')).toBeVisible();

    const loadedFamilies = await page.evaluate(async () => {
      await document.fonts.ready;
      return [...document.fonts].filter((face) => face.status === 'loaded').map((face) => face.family);
    });
    expect(loadedFamilies.length, 'no web font was loaded').toBeGreaterThan(0);

    await app.expectNoHorizontalScroll();
    await app.shot('landing');
    expect(networkGuard, 'requests outside localhost').toEqual([]);
  });

  for (const path of ['/pricing', '/security', '/legal/terms', '/legal/privacy']) {
    test(`${path} renders localized and themed`, { tag: ['@visual', '@responsive'] }, async ({ page, app }) => {
      await page.goto('/');
      await app.setLocale(app.locale);
      const response = await page.goto(path);
      expect(response?.status()).toBe(200);
      await app.expectLocale(app.locale);
      await app.expectTheme(app.theme);
      await expect(page.locator('h1').first()).toBeVisible();
      await app.expectNoHorizontalScroll();
      await app.shot(path.slice(1).replace(/\//g, '-'));
    });
  }

  test('pricing lists every plan of the contract', { tag: '@visual' }, async ({ page }) => {
    await page.goto('/pricing');
    for (const plan of ['free', 'starter', 'pro', 'business']) {
      await expect(page.getByTestId(`plan-card-${plan}`)).toBeVisible();
    }
  });

  test('SEO: localized title + description, Open Graph and hreflang alternates', { tag: '@visual' }, async ({ page, app }) => {
    await page.goto('/');
    await app.setLocale(app.locale);
    await expect(page).toHaveTitle(/\S/);
    for (const selector of ['meta[name="description"]', 'meta[property="og:title"]', 'meta[property="og:description"]']) {
      await expect(page.locator(selector), `${selector} must appear exactly once`).toHaveCount(1);
    }
    await expect(page.locator('meta[name="description"]')).toHaveAttribute('content', /\S{10,}/);
    await expect(page.locator('meta[property="og:title"]')).toHaveAttribute('content', /\S/);
    for (const lang of ['en', 'ru']) {
      await expect(page.locator(`link[rel="alternate"][hreflang="${lang}"]`)).toHaveCount(1);
    }
  });
});
