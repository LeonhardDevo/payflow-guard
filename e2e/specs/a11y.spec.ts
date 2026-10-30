import { expect, test } from '../fixtures.ts';

const PUBLIC_PAGES = ['/', '/pricing', '/security', '/legal/privacy', '/login', '/register', '/forgot-password'];

const APP_PAGES = [
  '/dashboard',
  '/knowledge',
  '/assistant',
  '/assistant/history',
  '/audit',
  '/settings/profile',
  '/settings/appearance',
  '/settings/workspace',
  '/settings/members',
  '/settings/billing',
  '/settings/integrations',
];

const slug = (path: string) => (path === '/' ? 'landing' : path.slice(1).replace(/\//g, '-'));

test.describe('accessibility', () => {
  test('public pages have no serious axe violations', { tag: '@visual' }, async ({ page, app }) => {
    await page.goto('/');
    await app.setLocale(app.locale);
    for (const path of PUBLIC_PAGES) {
      await test.step(path, async () => {
        await page.goto(path);
        await app.axe(slug(path), { soft: true });
        await app.shot(`a11y-${slug(path)}`);
      });
    }
  });

  test('signed-in pages have no serious axe violations', { tag: '@visual' }, async ({ page, app }) => {
    await app.loginAsDemo();
    await app.setLocale(app.locale);
    for (const path of APP_PAGES) {
      await test.step(path, async () => {
        await page.goto(path);
        await expect(page, `${path} redirected`).toHaveURL(new RegExp(`${path.replace(/\//g, '\\/')}(?:[?#]|$)`));
        await app.axe(slug(path), { soft: true });
        await app.shot(`a11y-${slug(path)}`);
      });
    }
  });

  test('AI admin page has no serious axe violations', { tag: '@visual' }, async ({ page, app }) => {
    await app.loginAsAdmin();
    await app.setLocale(app.locale);
    await page.goto('/admin/ai');
    await app.axe('admin-ai');
    await app.shot('a11y-admin-ai');
  });

  test('keyboard: the first Tab stop is visible and focus is indicated', { tag: '@visual' }, async ({ page }) => {
    await page.goto('/');
    await page.keyboard.press('Tab');
    const focus = await page.evaluate(() => {
      const element = document.activeElement as HTMLElement | null;
      if (!element || element === document.body) return null;
      const style = getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      return {
        tag: element.tagName,
        visible: rect.width > 0 && rect.height > 0,
        indicated: (style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0) || style.boxShadow !== 'none',
      };
    });
    expect(focus, 'Tab did not move focus into the page').not.toBeNull();
    expect(focus!.visible, `focused ${focus!.tag} is not visible`).toBe(true);
    expect(focus!.indicated, `focused ${focus!.tag} has no focus ring`).toBe(true);
  });

  test('interactive targets on the landing page are at least 24×24 px', { tag: ['@visual', '@responsive'] }, async ({ page }) => {
    await page.goto('/');
    const tooSmall = await page.evaluate(() =>
      [...document.querySelectorAll<HTMLElement>('a[href], button, [role="button"], input, select, textarea')]
        .filter((element) => {
          const rect = element.getBoundingClientRect();
          const style = getComputedStyle(element);
          if (style.visibility === 'hidden' || style.display === 'none' || rect.width === 0) return false;
          if (element.tagName === 'A' && style.display === 'inline') return false;
          return rect.width < 24 || rect.height < 24;
        })
        .map((element) => `${element.tagName.toLowerCase()} "${(element.getAttribute('aria-label') ?? element.textContent ?? '').trim().slice(0, 40)}"`),
    );
    expect(tooSmall, `targets smaller than 24px:\n${tooSmall.join('\n')}`).toEqual([]);
  });
});
