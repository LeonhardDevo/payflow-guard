import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import type { Page, TestInfo } from '@playwright/test';
import { E2E_DIR } from './config.ts';

export function screenshotPath(projectName: string, name: string): string {
  const safe = name
    .toLowerCase()
    .replace(/[^a-z0-9-_]+/g, '-')
    .replace(/^-+|-+$/g, '');
  if (!safe) throw new Error(`invalid screenshot name "${name}"`);
  return join(E2E_DIR, 'artifacts', projectName || 'default', `${safe}.png`);
}

export async function captureScreenshot(page: Page, testInfo: TestInfo, name: string, fullPage = true): Promise<string> {
  const path = screenshotPath(testInfo.project.name, name);
  mkdirSync(dirname(path), { recursive: true });
  await page.screenshot({ path, fullPage, animations: 'disabled', caret: 'hide' });
  await testInfo.attach(name, { path, contentType: 'image/png' });
  return path;
}
