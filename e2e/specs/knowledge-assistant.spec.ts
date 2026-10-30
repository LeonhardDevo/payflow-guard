import { join } from 'node:path';
import { E2E_DIR } from '../support/config.ts';
import { byTestIdAttr, expect, submitButton, test, uniqueId, type AppLocale } from '../fixtures.ts';

const FIXTURE = join(E2E_DIR, 'data', 'zephyr-travel-policy.md');

const QUESTION: Record<AppLocale, string> = {
  en: 'What is the Zephyr daily travel allowance?',
  ru: 'Какой суточный лимит командировки «Зефир»?',
};

const UNKNOWN_QUESTION: Record<AppLocale, string> = {
  en: 'Which pets are allowed on the orbital research station Kepler-9?',
  ru: 'Каких питомцев разрешено брать на орбитальную станцию «Кеплер-9»?',
};

test.describe('knowledge base & assistant', () => {
  test('uploaded Markdown is indexed and the assistant answers with a citation', { tag: '@flow' }, async ({
    page,
    app,
    owner: _owner,
  }, testInfo) => {
    const title = `E2E Zephyr policy ${uniqueId(testInfo)}`;

    await page.goto('/knowledge');
    await page.getByTestId('kb-upload-button').click();
    const dialog = page.getByRole('dialog');
    await dialog.locator('input[type="file"]').setInputFiles(FIXTURE);
    const titleField = dialog.locator('input[name="title"]').or(dialog.locator('input[id$="-title"]')).first();
    await expect(titleField, 'upload dialog has no title field').toBeVisible();
    await titleField.fill(title);
    await submitButton(dialog, 'kb-upload-submit').click();

    const row = byTestIdAttr(page, 'kb-row', 'title', title);
    await expect(row).toBeVisible();
    await expect(async () => {
      await page.reload();
      await expect(row.getByTestId('kb-status')).toHaveAttribute('data-status', 'indexed', { timeout: 2_000 });
    }).toPass({ timeout: 120_000, intervals: [1_000, 2_000, 3_000] });
    await app.shot('knowledge-indexed');

    await page.goto('/assistant');
    await page.locator('textarea[name="message"]').fill(QUESTION[app.locale]);
    await page.getByTestId('assistant-send').click();
    const answer = page.getByTestId('assistant-answer').last();
    await expect(answer).toBeVisible({ timeout: 60_000 });
    await expect(answer).toHaveAttribute('data-refused', 'false');
    await expect(answer).toContainText('4750');
    await expect(page.getByTestId('assistant-citation').filter({ hasText: title }).first()).toBeVisible();
    await app.shot('assistant-answer');

    await page.goto('/assistant/history');
    await expect(page.getByText(QUESTION[app.locale]).first()).toBeVisible();
  });

  test('the assistant refuses questions that are not covered by approved documents', { tag: '@flow' }, async ({
    page,
    app,
    owner: _owner,
  }) => {
    await page.goto('/assistant');
    await page.locator('textarea[name="message"]').fill(UNKNOWN_QUESTION[app.locale]);
    await page.getByTestId('assistant-send').click();
    const answer = page.getByTestId('assistant-answer').last();
    await expect(answer).toBeVisible({ timeout: 60_000 });
    await expect(answer).toHaveAttribute('data-refused', 'true');
    await expect(page.getByTestId('assistant-citation')).toHaveCount(0);
  });
});
