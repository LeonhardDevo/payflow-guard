import { expect, test, uniqueId } from '../fixtures.ts';

test.describe('audit log', () => {
  test('lists the workspace creation and the member invitation', { tag: '@flow' }, async ({ page, app, owner: _owner }, testInfo) => {
    await app.inviteMember(`e2e.audit.${uniqueId(testInfo)}@${app.config.slug}.test`);

    await page.goto('/audit');
    const rows = page.getByTestId('audit-row');
    await expect(rows.first()).toBeVisible();
    const actions = await rows.evaluateAll((elements) => elements.map((element) => element.getAttribute('data-action') ?? ''));
    expect(actions.some((action) => /invit/i.test(action)), `actions: ${actions.join(', ')}`).toBeTruthy();
    expect(actions.some((action) => /workspace/i.test(action)), `actions: ${actions.join(', ')}`).toBeTruthy();
    await app.shot('audit');
  });

  test('audit entries of another workspace are not visible (tenancy isolation)', { tag: '@flow' }, async ({ page, app, owner, openSession }, testInfo) => {
    const marker = `e2e.isolation.${uniqueId(testInfo)}@${app.config.slug}.test`;
    await app.inviteMember(marker);

    const other = await openSession();
    await other.app.registerOwner('isolated');
    await other.page.goto('/audit');
    await expect(other.page.getByText(marker)).toHaveCount(0);
    await expect(other.page.getByText(owner.workspace)).toHaveCount(0);
  });
});
