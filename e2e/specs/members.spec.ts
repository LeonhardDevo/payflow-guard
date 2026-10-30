import { byTestIdAttr, expect, test, uniqueId } from '../fixtures.ts';

test.describe('members & invitations', () => {
  test('owner invites a member who accepts and joins the workspace', { tag: '@flow' }, async ({
    page,
    app,
    owner,
    mailpit,
    openSession,
  }, testInfo) => {
    const inviteeEmail = `e2e.invitee.${uniqueId(testInfo)}@${app.config.slug}.test`;
    await app.inviteMember(inviteeEmail);
    await app.shot('members-invited');

    const invitationLink = await mailpit.waitForLink(inviteeEmail, /\/invitations\//, 45_000);

    const invitee = await openSession();
    await invitee.app.register('invitee', inviteeEmail);
    await invitee.page.goto(invitationLink);
    await expect(invitee.page.getByTestId('invitation-workspace')).toContainText(owner.workspace);
    await invitee.app.shot('invitation-accept');
    await invitee.page.getByTestId('invitation-accept').click();
    await expect(invitee.page).toHaveURL(/\/dashboard/, { timeout: 20_000 });
    await expect(invitee.page.getByText(owner.workspace, { exact: false }).first()).toBeVisible();

    await page.goto('/settings/members');
    await expect(byTestIdAttr(page, 'member-row', 'email', inviteeEmail)).toBeVisible();
    await expect(byTestIdAttr(page, 'invitation-row', 'email', inviteeEmail)).toHaveCount(0);
    await app.shot('members-joined');
  });

  test('an invitation cannot be accepted by a different account', { tag: '@flow' }, async ({ app, mailpit, openSession }, testInfo) => {
    const inviteeEmail = `e2e.target.${uniqueId(testInfo)}@${app.config.slug}.test`;
    await app.registerOwner('inviter');
    await app.inviteMember(inviteeEmail);
    const link = await mailpit.waitForLink(inviteeEmail, /\/invitations\//, 45_000);

    const stranger = await openSession();
    await stranger.app.register('stranger');
    const response = await stranger.page.goto(link);
    const accept = stranger.page.getByTestId('invitation-accept');
    const refusedUpFront =
      (response?.status() ?? 0) >= 400 || (await accept.count()) === 0 || (await accept.isDisabled());
    if (!refusedUpFront) {
      await accept.click();
      await expect(stranger.page).not.toHaveURL(/\/dashboard/);
    }
    await stranger.page.goto('/dashboard');
    await expect(stranger.page, 'the stranger must not have joined any workspace').toHaveURL(/\/onboarding\/workspace/);
  });
});
