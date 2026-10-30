import { appRequest } from '../support/http.ts';
import { expect, test } from '../fixtures.ts';

const PROVIDER = 'openai';

test.describe('AI admin', () => {
  test.describe.configure({ mode: 'serial' });

  test('admin saves a key (only …last4 shown) and the connection test passes', { tag: '@flow' }, async ({ page, app }) => {
    await app.loginAsAdmin();
    await page.goto('/admin/ai');

    for (const provider of ['mock', 'anthropic', 'openai', 'ollama']) {
      await expect(page.locator(`[data-test="ai-provider-row"][data-provider="${provider}"]`), provider).toBeVisible();
    }

    const last4 = Math.random().toString(36).slice(2, 6).toUpperCase().padEnd(4, 'X');
    const key = `sk-e2e-${Date.now().toString(36)}-${last4}`;
    try {
      await page.getByTestId(`ai-key-input-${PROVIDER}`).fill(key);
      await page.getByTestId(`ai-key-save-${PROVIDER}`).click();
      await expect(page.getByTestId(`ai-key-hint-${PROVIDER}`)).toContainText(last4);
      await expect(page.getByTestId(`ai-key-source-${PROVIDER}`)).toHaveAttribute('data-source', 'settings');

      await page.reload();
      await expect(page.getByTestId(`ai-key-hint-${PROVIDER}`)).toContainText(last4);
      expect(await page.content()).not.toContain(key);

      await page.getByTestId('ai-test-connection').click();
      await expect(page.getByTestId('ai-test-result')).toHaveAttribute('data-ok', 'true', { timeout: 30_000 });
      await app.shot('admin-ai');
    } finally {
      const response = await appRequest(page, `/admin/ai/keys/${PROVIDER}`, { method: 'PUT', data: { api_key: null } });
      expect(response.status(), 'clearing the e2e key').toBeLessThan(400);
    }
  });

  test('workspace owners without platform admin rights cannot open it', { tag: '@flow' }, async ({ page, app }) => {
    await app.loginAsDemo();
    const response = await page.goto('/admin/ai');
    expect(response?.status()).toBe(403);
  });
});
