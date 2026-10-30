import type { Page } from '@playwright/test';
import { appRequest } from '../support/http.ts';
import { chooseOption, completeStepUp, expect, expectNoEmoji, PF, pfLogin, resetDemo, test } from '../support/payflow.ts';

test.describe.configure({ mode: 'serial', timeout: 420_000 });

const IBAN_RE = /[A-Z]{2}\d{2}[A-Z0-9]{10,30}/;
const HIGH_RISK_SIGNALS = ['lookalike_domain', 'holder_name_mismatch', 'country_changed', 'urgency_language'];
const VERIFY_BODY = {
  method: 'callback_known_phone',
  checklist: { called_known_number: true, confirmed_holder_name: true, confirmed_last4: true, confirmed_request_origin: true },
  note: 'Called the known number and confirmed the request',
};

async function pickSelect(page: Page, triggerId: string, option: string | RegExp): Promise<void> {
  await page.locator(`#${triggerId}`).click();
  await page.getByRole('option', { name: option }).first().click();
}

async function checkAll(page: Page): Promise<void> {
  for (const key of ['called_known_number', 'confirmed_holder_name', 'confirmed_last4', 'confirmed_request_origin']) {
    await page.getByTestId(`check-${key}`).click();
  }
}

async function stepUpIfAsked(page: Page, email: string): Promise<void> {
  const dialog = page.getByTestId('step-up-dialog');
  if (await dialog.isVisible({ timeout: 4_000 }).catch(() => false)) await completeStepUp(page, email);
}

async function verify(page: Page, email: string, note?: string): Promise<void> {
  await checkAll(page);
  if (note) await page.locator('#verify-note').fill(note);
  await page.getByTestId('verify-submit').click();
  await stepUpIfAsked(page, email);
  await expect(page.getByTestId('change-status')).toHaveAttribute('data-status', 'verified', { timeout: 20_000 });
}

async function approve(page: Page, email: string): Promise<void> {
  await page.getByTestId('payout-approve').click();
  await page.getByTestId('approve-confirm').click();
  await stepUpIfAsked(page, email);
}

async function signalCodes(page: Page): Promise<string[]> {
  return page.getByTestId('risk-signals').locator('li').evaluateAll((items) => items.map((item) => item.getAttribute('data-code') ?? ''));
}

async function pendingReviewHref(page: Page): Promise<string> {
  await expect(page.getByTestId('vendor-pending-change')).toBeVisible();
  const href = await page.getByTestId('vendor-pending-change').getByRole('link').first().getAttribute('href');
  expect(href).toBeTruthy();
  return href!;
}

test.describe('PayFlow Payment Guard', () => {
  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await resetDemo(page);
    await page.close();
  });

  test('J4 a changed vendor account is detected and its payout is blocked', { tag: '@flow' }, async ({ page, app }) => {
    await pfLogin(page, PF.demoOwner);
    await app.setLocale(app.locale);

    await page.goto('/guard');
    await expect(page.locator('main, #main').first()).toContainText('PixelForge');
    await expectNoEmoji(page);
    await app.shot('j4-guard-inbox');

    await page.goto('/vendors');
    await page.getByRole('link', { name: /PixelForge Studio/ }).first().click();
    await expect(page.getByTestId('vendor-pending-change')).toBeVisible();
    await page.getByTestId('vendor-pending-change').getByRole('link').first().click();
    await expect(page).toHaveURL(/\/guard\/bank-changes\/\d+$/);
    await expect(page.getByTestId('risk-badge').first()).toHaveAttribute('data-band', 'high');
    const codes = await signalCodes(page);
    for (const code of HIGH_RISK_SIGNALS) expect(codes).toContain(code);
    const review = await page.locator('main, #main').first().innerText();
    expect(review).toContain('0519');
    expect(review, 'full IBAN must stay masked until revealed').not.toMatch(IBAN_RE);
    await expectNoEmoji(page);
    await app.axe('j4-pixelforge-review');

    await page.goto('/payouts?tab=all&q=PO-2026-0087');
    await page.locator('[data-test="payout-row"][data-number="PO-2026-0087"]').first().click();
    await expect(page.getByTestId('payout-status')).toHaveAttribute('data-status', 'on_hold');
    await expect(page.getByTestId('payout-approve')).toHaveCount(0);
    await expect(page.getByTestId('payout-release')).toHaveCount(0);
    const payoutText = await page.locator('main, #main').first().innerText();
    expect(payoutText).not.toMatch(IBAN_RE);
    await app.shot('j4-payout-on-hold');
  });

  test('J4 vendor details are verified by a second person, a risky change enters cooling-off and the payout needs two approvals', { tag: '@flow' }, async ({ page, app, openSession }) => {
    await pfLogin(page, PF.finance);
    await app.setLocale(app.locale);

    await page.goto('/vendors/create');
    await page.locator('#vendor-name').fill('Acme Dev');
    await page.locator('#vendor-email').fill('billing@acmedev.example');
    await page.locator('#vendor-phone').fill('+49 30 1234567');
    await chooseOption(page, 'vendor-country', /Germany|Германия/);
    await pickSelect(page, 'vendor-currency', 'EUR');
    await page.getByTestId('vendor-with-bank').click();
    await page.locator('#vendor-bank-holder').fill('Acme Dev GmbH');
    await page.getByTestId('bank-iban').fill('DE44500105175407324931');
    await chooseOption(page, 'vendor-bank-country', /Germany|Германия/);
    await pickSelect(page, 'vendor-bank-currency', 'EUR');
    await page.getByTestId('vendor-save').click();
    await expect(page).toHaveURL(/\/vendors\/\d+$/);
    const vendorUrl = new URL(page.url()).pathname;
    const initialReview = await pendingReviewHref(page);

    await page.goto(initialReview);
    await expect(page.getByTestId('cannot-verify')).toHaveAttribute('data-reason', 'is_requester');
    await expect(page.getByTestId('verify-form')).toHaveCount(0);

    const approver = await openSession();
    await pfLogin(approver.page, PF.approver);
    await approver.page.goto(initialReview);
    await verify(approver.page, PF.approver);

    await page.goto(`${vendorUrl}/bank-changes/create`);
    await page.locator('#change-holder').fill('Acme Payments UAB');
    await page.getByTestId('bank-iban').fill('LT787300010133224466');
    await chooseOption(page, 'change-country', /Lithuania|Литва/);
    await pickSelect(page, 'change-currency', 'EUR');
    await page.getByTestId('change-contact').fill('billing@acmedev-payments.example');
    await page.getByTestId('change-text').fill('Please update our bank account urgently, the old one is closed. Urgent payment needed today.');
    await page.getByTestId('bank-change-submit').click();
    await expect(page).toHaveURL(/\/guard\/bank-changes\/\d+$/);
    const changeReview = new URL(page.url()).pathname;
    await expect(page.getByTestId('risk-badge').first()).toHaveAttribute('data-band', 'high');
    const codes = await signalCodes(page);
    for (const code of HIGH_RISK_SIGNALS) expect(codes, `signals: ${codes.join(', ')}`).toContain(code);
    await expect(page.locator('[data-test="bank-diff"] [data-field="account"]')).toHaveAttribute('data-changed', 'true');
    await expect(page.getByTestId('cannot-verify')).toHaveAttribute('data-reason', 'is_requester');
    await expect(page.getByTestId('verify-form')).toHaveCount(0);
    await app.axe('j4-bank-change-review');
    await app.shot('j4-review');

    const self = await appRequest(page, `${changeReview}/verify`, { data: VERIFY_BODY });
    expect(self.status()).toBe(403);

    const fresh = await openSession();
    await pfLogin(fresh.page, PF.newApprover);
    await fresh.page.goto(changeReview);
    await expect(fresh.page.getByTestId('cannot-verify')).toHaveAttribute('data-reason', 'not_eligible_yet');
    const early = await appRequest(fresh.page, `${changeReview}/verify`, { data: VERIFY_BODY });
    expect([403, 409]).toContain(early.status());
    expect(((await early.json()) as { code?: string }).code).toBe('not_eligible_yet');

    await approver.page.goto(changeReview);
    await verify(approver.page, PF.approver, VERIFY_BODY.note);
    await approver.app.shot('j4-verified');
    await approver.page.goto(vendorUrl);
    await expect(approver.page.getByTestId('cooling-off')).toBeVisible();

    await page.goto(`/payouts/create?vendor_id=${vendorUrl.split('/').pop()}`);
    await page.getByTestId('payout-amount').fill('900.00');
    await page.locator('#po-desc').fill('Sprint 42 development');
    await page.getByTestId('payout-submit').click();
    await expect(page).toHaveURL(/\/payouts\/\d+$/);
    const payoutUrl = new URL(page.url()).pathname;
    const payoutNumber = (await page.title()).split(' ')[0] ?? '';
    await expect(page.getByTestId('payout-status')).toHaveAttribute('data-status', 'pending_approval');
    expect(await signalCodes(page)).toContain('cooling_off');
    await expect(page.getByTestId('payout-approve')).toHaveCount(0);

    await approver.page.goto(payoutUrl);
    await approve(approver.page, PF.approver);
    await expect(approver.page.getByTestId('approvals').locator('li')).toHaveCount(1, { timeout: 20_000 });
    await expect(approver.page.getByTestId('payout-status')).toHaveAttribute('data-status', 'pending_approval');
    await expect(approver.page.getByTestId('payout-approve')).toHaveCount(0);

    const admin = await openSession();
    await pfLogin(admin.page, PF.admin);
    await admin.page.goto(payoutUrl);
    await approve(admin.page, PF.admin);
    await expect(admin.page.getByTestId('payout-status')).toHaveAttribute('data-status', 'approved', { timeout: 20_000 });

    await page.goto(payoutUrl);
    await page.getByTestId('payout-release').click();
    await page.getByTestId('release-reference').fill('SEPA-REF-001');
    const typed = page.getByTestId('release-type-number');
    if (await typed.isVisible().catch(() => false)) await typed.fill(payoutNumber);
    await page.getByTestId('release-confirm').click();
    await stepUpIfAsked(page, PF.finance);
    await expect(page.getByTestId('payout-status')).toHaveAttribute('data-status', 'released', { timeout: 20_000 });
    await expectNoEmoji(page);
    await app.shot('j4-payout');

    const owner = await openSession();
    await pfLogin(owner.page, PF.owner);
    for (const action of ['payflow.bank_account.requested', 'payflow.bank_account.verified', 'payflow.payout.approved', 'payflow.payout.released']) {
      await owner.page.goto(`/audit?action=${action}`);
      await expect(owner.page.locator(`[data-test="audit-row"][data-action="${action}"]`).first(), action).toBeVisible();
    }
    await owner.page.goto('/audit?action=payflow.payout.approved');
    await expect(owner.page.locator('[data-test="audit-row"][data-action="payflow.payout.approved"]')).toHaveCount(2);
    const auditText = await owner.page.locator('main, #main').first().innerText();
    expect(auditText).not.toMatch(IBAN_RE);
    await owner.app.shot('j4-audit');
  });

  test('J4 records of another workspace cannot be opened or decided by ID', { tag: '@flow' }, async ({ page, openSession }) => {
    await pfLogin(page, PF.demoOwner);
    await page.goto('/payouts?tab=all&q=PO-2026-0092');
    await page.locator('[data-test="payout-row"][data-number="PO-2026-0092"]').first().click();
    await expect(page).toHaveURL(/\/payouts\/\d+$/);
    const payout = new URL(page.url()).pathname;
    await page.goto('/invoices?q=NS-2026-0118');
    await page.locator('[data-test="invoice-row"][data-number="NS-2026-0118"] a').first().click();
    await expect(page).toHaveURL(/\/invoices\/\d+$/);
    const invoice = new URL(page.url()).pathname;
    await page.goto('/vendors');
    await page.getByRole('link', { name: /PixelForge Studio/ }).first().click();
    await expect(page).toHaveURL(/\/vendors\/\d+$/);
    const vendor = new URL(page.url()).pathname;

    const outsider = await openSession();
    await pfLogin(outsider.page, PF.owner);
    for (const path of [payout, invoice, vendor]) {
      const response = await outsider.page.request.get(path, { headers: { Accept: 'text/html' } });
      expect([403, 404], `${path} from E2E Lab`).toContain(response.status());
    }
    const decision = await appRequest(outsider.page, `${payout}/approve`, { data: {} });
    expect([403, 404]).toContain(decision.status());
  });

  test('J4 an admin cannot hand out the approver role', { tag: '@flow' }, async ({ page }) => {
    await pfLogin(page, PF.admin);
    await page.goto('/settings/members');
    const response = await appRequest(page, '/settings/members/invitations', { data: { email: 'sock@e2e.example', role: 'approver' } });
    expect([403, 422]).toContain(response.status());
  });
});
