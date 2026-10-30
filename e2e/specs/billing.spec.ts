import { createHmac } from 'node:crypto';
import type { Page } from '@playwright/test';
import { config, expect, test, type AppHelpers } from '../fixtures.ts';
import { readAppEnv, RESOLVED_MODE_ENV } from '../support/config.ts';
import { subscriptionForOwner } from '../support/db.ts';

const WEBHOOK_SECRET = readAppEnv(config.project, 'worker').STRIPE_WEBHOOK_SECRET?.trim() || 'whsec_test_local';

async function payFakeCheckout(page: Page, app: AppHelpers, plan: string, shots = false): Promise<void> {
  await page.goto('/settings/billing');
  await expect(page.getByTestId(`plan-card-${plan}`)).toBeVisible();
  if (shots) await app.shot('billing-free');

  await page.getByTestId(`plan-subscribe-${plan}`).click();
  await expect(page).toHaveURL(/\/dev\/fake-checkout\//, { timeout: 20_000 });
  if (shots) {
    await app.axe('fake-checkout', { soft: true });
    await app.shot('fake-checkout');
  }

  await page.getByTestId('fake-checkout-pay').click();
  await expect(page).toHaveURL(/\/settings\/billing/, { timeout: 20_000 });

  const status = page.getByTestId('subscription-status');
  await expect(async () => {
    await page.reload();
    await expect(status).toHaveAttribute('data-status', 'active', { timeout: 2_000 });
  }).toPass({ timeout: 60_000, intervals: [1_000, 2_000] });
  await expect(status).toHaveAttribute('data-plan', plan);
}

function signedStripePost(payload: string): Promise<Response> {
  const timestamp = Math.floor(Date.now() / 1000);
  const signature = createHmac('sha256', WEBHOOK_SECRET).update(`${timestamp}.${payload}`).digest('hex');
  return fetch(`${config.workerURL}/webhooks/stripe`, {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'stripe-signature': `t=${timestamp},v1=${signature}` },
    body: payload,
  });
}

test.describe('billing', () => {
  test('fake checkout activates a paid subscription through the real webhook path', { tag: '@flow' }, async ({
    page,
    app,
    owner: _owner,
  }) => {
    await payFakeCheckout(page, app, 'starter', true);
    await app.shot('billing-active');
    const status = page.getByTestId('subscription-status');

    await page.getByTestId('plan-subscribe-pro').click();
    await page.getByTestId('confirm-dialog-confirm').click();
    await expect(async () => {
      await page.reload();
      await expect(status).toHaveAttribute('data-plan', 'pro', { timeout: 2_000 });
    }).toPass({ timeout: 60_000, intervals: [1_000, 2_000] });

    await page.getByTestId('billing-cancel').click();
    await page.getByTestId('confirm-dialog-confirm').click();
    await expect(async () => {
      await page.reload();
      await expect(page.getByTestId('billing-resume')).toBeVisible({ timeout: 2_000 });
    }).toPass({ timeout: 60_000, intervals: [1_000, 2_000] });
    await app.shot('billing-cancel-scheduled');

    await page.getByTestId('billing-resume').click();
    await expect(async () => {
      await page.reload();
      await expect(page.getByTestId('billing-cancel')).toBeVisible({ timeout: 2_000 });
    }).toPass({ timeout: 60_000, intervals: [1_000, 2_000] });
  });

  test('a failed renewal shows the past-due alert and e-mails the owner', { tag: '@flow' }, async ({ page, app, owner, mailpit }) => {
    await payFakeCheckout(page, app, 'starter');

    const database = process.env[RESOLVED_MODE_ENV] === 'reuse' ? config.database.devName : config.database.e2eName;
    const subscription = await subscriptionForOwner(config.database, database, owner.email);
    expect(subscription).not.toBeNull();
    const { workspace_id: workspaceId, stripe_subscription_id: subscriptionId, stripe_customer_id: customerId } = subscription!;

    const created = Math.floor(Date.now() / 1000) + 5;
    const payload = JSON.stringify({
      id: `evt_e2e_failed_${subscriptionId}`,
      object: 'event',
      api_version: '2025-09-30.clover',
      created,
      livemode: false,
      type: 'invoice.payment_failed',
      data: {
        object: {
          id: `in_e2e_${subscriptionId}`,
          object: 'invoice',
          customer: customerId,
          billing_reason: 'subscription_cycle',
          amount_due: 1900,
          currency: 'usd',
          next_payment_attempt: created + 3 * 86_400,
          parent: {
            type: 'subscription_details',
            subscription_details: { subscription: subscriptionId, metadata: { workspace_id: String(workspaceId) } },
          },
        },
      },
    });
    const response = await signedStripePost(payload);
    expect(response.status).toBe(200);

    const alert = page.getByTestId('billing-alert');
    await expect(async () => {
      await page.reload();
      await expect(page.getByTestId('subscription-status')).toHaveAttribute('data-status', 'past_due', { timeout: 2_000 });
    }).toPass({ timeout: 60_000, intervals: [1_000, 2_000] });
    await expect(alert).toHaveAttribute('role', 'alert');
    await app.shot('billing-past-due');

    const mail = await mailpit.waitForMail({ to: owner.email, subject: /Payment failed|Не удалось списать оплату/, timeoutMs: 60_000 });
    expect(mail.Subject.length).toBeGreaterThan(0);
  });

  test('cancelling the fake checkout keeps the workspace unsubscribed', { tag: '@flow' }, async ({ page, owner: _owner }) => {
    await page.goto('/settings/billing');
    await page.getByTestId('plan-subscribe-pro').click();
    await expect(page).toHaveURL(/\/dev\/fake-checkout\//, { timeout: 20_000 });
    await page.getByTestId('fake-checkout-cancel').click();
    await expect(page).toHaveURL(/\/settings\/billing/, { timeout: 20_000 });
    await page.reload();
    const status = page.getByTestId('subscription-status');
    if ((await status.count()) > 0) await expect(status).not.toHaveAttribute('data-status', 'active');
  });

  test('billing plans fit a phone screen without horizontal scroll', { tag: '@responsive' }, async ({ page, app, owner: _owner }) => {
    await page.goto('/settings/billing');
    await expect(page.getByTestId('plan-card-starter')).toBeVisible();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
    await app.shot('billing-mobile');
  });
});
