import { expect, test } from '../fixtures.ts';

test.describe('auth & onboarding', () => {
  test('register → verify e-mail → create workspace → dashboard', { tag: '@flow' }, async ({ page, app }) => {
    const account = await app.registerOwner('signup');

    await expect(page).toHaveURL(/\/dashboard/);
    await expect(page.getByTestId('dashboard-stats')).toBeVisible();
    await expect(page.getByText(account.workspace, { exact: false }).first()).toBeVisible();
    await app.shot('dashboard-new-owner');
  });

  test('a verified user without a workspace is sent to onboarding', { tag: '@flow' }, async ({ page, app }) => {
    await app.register('noworkspace');
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/onboarding\/workspace/);
    await app.axe('onboarding', { soft: true });
    await app.shot('onboarding');
  });

  test('guests are redirected to the login page', { tag: '@flow' }, async ({ page }) => {
    for (const path of ['/dashboard', '/settings/members', '/knowledge', '/assistant', '/audit']) {
      await page.goto(path);
      await expect(page, `${path} must require auth`).toHaveURL(/\/login(?:[?#]|$)/);
    }
  });

  test('seeded demo owner signs in and sees the demo workspace', { tag: '@flow' }, async ({ page, app }) => {
    await app.loginAsDemo();
    await page.goto('/dashboard');
    await expect(page.getByTestId('dashboard-stats')).toBeVisible();
    await app.shot('dashboard-demo');
  });

  test('wrong password is rejected with a localized error', { tag: '@flow' }, async ({ page, app }) => {
    await page.goto('/');
    await app.setLocale(app.locale);
    await page.goto('/login');
    await page.locator('input[name="email"]').fill(app.config.credentials.demo);
    await page.locator('input[name="password"]').fill('definitely-wrong-password');
    await page.getByTestId('login-button').click();
    await expect(page).toHaveURL(/\/login/);
    const error = page.locator('[role="alert"], .text-destructive, [data-test="input-error"]').first();
    await expect(error).toBeVisible();
    if (app.locale === 'ru') await expect(error).toContainText(/[А-Яа-яЁё]/);
  });
});
