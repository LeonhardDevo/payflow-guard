import { existsSync, rmSync } from 'node:fs';
import { expect, test } from '../fixtures.ts';
import { expectAccessible } from '../support/a11y.ts';
import { screenshotPath } from '../support/artifacts.ts';

const ORIGIN = 'http://selftest.localhost';

test.beforeEach(async ({ page }) => {
  await page.route(`${ORIGIN}/**`, (route) =>
    route.fulfill({
      contentType: 'text/html',
      body: '<!doctype html><html lang="en"><head><title>Self-test</title></head><body><main><h1>Self-test</h1><a href="#x">Link</a></main></body></html>',
    }),
  );
});

test('network guard blocks and records every non-localhost request', async ({ page, networkGuard }) => {
  await page.goto(ORIGIN);
  await page.setContent(
    '<img alt="" src="https://cdn.example.invalid/pixel.png"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter">',
  );
  await expect.poll(() => networkGuard.length).toBeGreaterThanOrEqual(2);
  expect(networkGuard.map((request) => new URL(request.url).hostname).sort()).toEqual(['cdn.example.invalid', 'fonts.googleapis.com']);
  expect(networkGuard.map((request) => request.resourceType).sort()).toEqual(['image', 'stylesheet']);
  networkGuard.length = 0;
});

test('network guard lets local, data: and blob: resources through', async ({ page, networkGuard }) => {
  await page.goto(ORIGIN);
  await page.setContent(
    '<img alt="" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="><img alt="" src="http://127.0.0.1:9/x.png">',
  );
  await page.waitForTimeout(300);
  expect(networkGuard).toEqual([]);
});

test('axe helper fails on serious/critical violations', async ({ page }, testInfo) => {
  await page.goto(ORIGIN);
  await page.setContent('<html lang="en"><body><main><img src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="><button></button></main></body></html>');
  await expect(expectAccessible(page, testInfo, 'selftest-bad')).rejects.toThrow(/image-alt|button-name/);
});

test('axe helper passes an accessible page', async ({ page, app }) => {
  await page.goto(ORIGIN);
  await app.axe('selftest-good');
});

test('screenshot helper writes e2e/artifacts/<project>/<name>.png', async ({ page, app }, testInfo) => {
  await page.goto(ORIGIN);
  const path = await app.shot('Selftest Screenshot!');
  expect(path).toBe(screenshotPath(testInfo.project.name, 'selftest-screenshot'));
  expect(existsSync(path)).toBe(true);
  rmSync(path);
});

test('the project theme is pre-seeded for the app before any script runs', async ({ page, appTheme }) => {
  await page.goto(ORIGIN);
  expect(await page.evaluate(() => localStorage.getItem('appearance'))).toBe(appTheme);
});

test('network guard fails the test when a CDN request is left unhandled', async ({ page }) => {
  test.fail(true, 'the guard must turn an external request into a test failure');
  await page.goto(ORIGIN);
  await page.setContent('<script src="https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.js"></script>');
  await page.waitForTimeout(300);
});
