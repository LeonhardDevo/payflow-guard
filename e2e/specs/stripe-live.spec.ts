import { expect, test } from '../fixtures.ts';

const liveKey = process.env.STRIPE_SECRET_KEY ?? '';

test.describe('stripe live (test mode)', () => {
  test.skip(!liveKey.startsWith('sk_test_'), 'Runs only with a Stripe test-mode secret key (sk_test_…).');

  test('redirects to the hosted Stripe Checkout for a paid plan', { tag: '@stripe-live' }, async ({ page, owner: _owner }) => {
    await page.goto('/settings/billing');
    await expect(page.getByTestId('billing-market')).toBeVisible();

    await page.getByTestId('plan-subscribe-starter').click();
    await expect(page).toHaveURL(/^https:\/\/checkout\.stripe\.com\//, { timeout: 30_000 });
    await expect(page.getByText(/test mode/i).first()).toBeVisible({ timeout: 30_000 });
  });
});
