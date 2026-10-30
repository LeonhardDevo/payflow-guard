import type { Locator, Page } from '@playwright/test';
import type { AppLocale } from '../fixtures.ts';
import { expect, expectNoEmoji, messageTexts, PF, pfLogin, resetDemo, telegram, test } from '../support/payflow.ts';

test.describe.configure({ mode: 'serial', timeout: 420_000 });

const IBAN_RE = /[A-Z]{2}\d{2}[A-Z0-9]{10,30}/;

interface Questions {
  about: string;
  remind: string;
  penalty: string;
  overdue: string;
  changes: string;
  approve: string;
  unknown: string;
  safe: string;
}

const Q: Record<AppLocale, Questions> = {
  en: {
    about: 'Tell me about Helios Media',
    remind: 'When should we remind this client?',
    penalty: 'What penalty does the contract with Baltic Logistics set?',
    overdue: 'Which invoice is the most overdue?',
    changes: 'Which bank details did PixelForge change?',
    approve: 'Approve PO-2026-0089',
    unknown: 'What is our crypto refund policy?',
    safe: 'When will Helios pay invoice NS-2026-0118?',
  },
  ru: {
    about: 'Расскажи о Helios Media',
    remind: 'Когда напомнить этому клиенту?',
    penalty: 'Какой штраф предусмотрен договором с Балтийской логистикой?',
    overdue: 'Какой номер счёта просрочен сильнее всего?',
    changes: 'Какие реквизиты у поставщика PixelForge были изменены?',
    approve: 'Согласуй выплату PO-2026-0089',
    unknown: 'Какая у нас политика возврата в криптовалюте?',
    safe: 'Когда Helios оплатит счёт NS-2026-0118?',
  },
};

const plain = (text: string): string => text.replace(/[\s  ]+/g, ' ');

async function waitForIndexedKnowledge(page: Page): Promise<void> {
  await expect(async () => {
    await page.goto('/knowledge');
    const statuses = await page.locator('[data-test="kb-status"]').evaluateAll((items) => items.map((item) => item.getAttribute('data-status')));
    expect(statuses.length, 'seeded documents').toBeGreaterThanOrEqual(12);
    expect(statuses.filter((status) => status !== 'indexed'), 'documents still indexing').toEqual([]);
  }).toPass({ timeout: 240_000, intervals: [2_000, 3_000, 5_000] });
}

async function ask(page: Page, question: string): Promise<Locator> {
  const before = await page.getByTestId('assistant-answer').count();
  await page.locator('textarea[name="message"]').fill(question);
  await page.getByTestId('assistant-send').click();
  await expect(page.getByTestId('assistant-answer')).toHaveCount(before + 1, { timeout: 90_000 });
  const answer = page.getByTestId('assistant-answer').last();
  await expect(answer.locator('.answer-prose')).toBeVisible();
  return answer;
}

async function answerText(answer: Locator): Promise<string> {
  return plain(await answer.locator('.answer-prose').innerText());
}

test.describe('PayFlow assistant answers from live data and approved documents', () => {
  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await resetDemo(page);
    await page.close();
  });

  test('J6 the four headline questions are answered with facts, citations and masked bank data', { tag: '@flow' }, async ({ page, app }) => {
    const q = Q[app.locale];
    await pfLogin(page, PF.demoOwner);
    await app.setLocale(app.locale);
    await waitForIndexedKnowledge(page);

    await page.goto('/assistant');
    const about = await ask(page, q.about);
    await expect(about).toHaveAttribute('data-refused', 'false');
    expect(await answerText(about)).toContain('Helios');

    const remind = await ask(page, q.remind);
    await expect(remind).toHaveAttribute('data-refused', 'false');
    const remindText = await answerText(remind);
    expect(remindText).toContain('NS-2026-0118');
    expect(remindText).toMatch(app.locale === 'ru' ? /последнее напоминание/i : /final/i);
    expect(remindText).toMatch(app.locale === 'ru' ? /позвон/i : /call/i);
    await expect(remind.getByTestId('assistant-live-data')).toBeVisible();
    await expect(remind.getByTestId('assistant-citation').first()).toBeVisible();

    const penalty = await ask(page, q.penalty);
    await expect(penalty).toHaveAttribute('data-refused', 'false');
    const penaltyText = await answerText(penalty);
    for (const fact of app.locale === 'ru' ? ['0,1%', 'в день', '10%', '26,95 €'] : ['0.1%', '10%', '€26.95']) expect(penaltyText).toContain(fact);
    const firstCitation = penalty.getByTestId('assistant-citation').first();
    await expect(firstCitation).toContainText('1');
    await expect(firstCitation).toContainText(app.locale === 'ru' ? /Балтийская логистика/ : /Baltic Logistics|Балтийская логистика/);

    const overdue = await ask(page, q.overdue);
    await expect(overdue).toHaveAttribute('data-refused', 'false');
    const overdueText = await answerText(overdue);
    expect(overdueText).toContain('NS-2026-0118');
    expect(overdueText).toContain('52');
    expect(overdueText).toContain(app.locale === 'ru' ? '12 400,00 $' : '$12,400.00');
    await expect(overdue.getByTestId('assistant-live-data')).toBeVisible();

    const changes = await ask(page, q.changes);
    await expect(changes).toHaveAttribute('data-refused', 'false');
    const changesText = await answerText(changes);
    expect(changesText).toContain('•••• 0519');
    expect(changesText).toContain('LT');
    expect(changesText).toContain('100/100');
    expect(changesText).not.toMatch(IBAN_RE);
    expect(changesText).not.toContain('PF Studio Payments UAB');
    await expect(changes.getByTestId('assistant-citation').first()).toBeVisible();
    const pageText = await page.locator('main, #main').first().innerText();
    expect(pageText).not.toMatch(IBAN_RE);
    await expectNoEmoji(page);
    await app.shot('j6-assistant');
    await app.axe('j6-assistant');
  });

  test('J6 mutations are refused, unknown policies are not invented and payment questions are not refused', { tag: '@flow' }, async ({ page, app }) => {
    const q = Q[app.locale];
    await pfLogin(page, PF.demoOwner);
    await app.setLocale(app.locale);
    await page.goto('/assistant');

    const approve = await ask(page, q.approve);
    await expect(approve).toHaveAttribute('data-refused', 'true');
    await expect(approve.getByRole('button').filter({ hasText: app.locale === 'ru' ? /приложени/i : /app/i }).first()).toBeVisible();

    const unknown = await ask(page, q.unknown);
    await expect(unknown).toHaveAttribute('data-refused', 'true');

    const safe = await ask(page, q.safe);
    await expect(safe).toHaveAttribute('data-refused', 'false');
    expect(await answerText(safe)).toContain('NS-2026-0118');
  });

  test('J6 the penalty question is answered in Telegram with a numbered source', { tag: '@flow' }, async ({ page }) => {
    await page.goto('/login');
    const outbound = await telegram(page, { id: 700000003, language: 'ru' }, { text: 'Какой штраф предусмотрен договором с Балтийской логистикой?' });
    const text = messageTexts(outbound);
    expect(text).toContain('0,1%');
    expect(text).toMatch(/(?:^|\n)\s*(?:\[1\]|1[.)])\s/m);
    expect(text).not.toMatch(IBAN_RE);
  });
});
