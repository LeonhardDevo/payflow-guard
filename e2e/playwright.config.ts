import { defineConfig, devices, type PlaywrightTestConfig } from '@playwright/test';
import type { AppLocale, AppTheme } from './fixtures.ts';
import { loadConfig } from './support/config.ts';
import { stripeLiveKey } from './support/services.ts';

const cfg = loadConfig();
const stripeLive = stripeLiveKey() !== null;
const ci = Boolean(process.env.CI);

interface Variant {
  locale: AppLocale;
  theme: AppTheme;
  grep?: RegExp;
}

const VARIANTS: Variant[] = [
  { locale: 'en', theme: 'light' },
  { locale: 'ru', theme: 'dark' },
  { locale: 'en', theme: 'dark', grep: /@visual/ },
  { locale: 'ru', theme: 'light', grep: /@visual/ },
];

const browserLocale = (locale: AppLocale) => (locale === 'ru' ? 'ru-RU' : 'en-US');

interface ProjectOptions {
  appLocale: AppLocale;
  appTheme: AppTheme;
}

type Projects = NonNullable<PlaywrightTestConfig<ProjectOptions>['projects']>;

const desktop: Projects = VARIANTS.map((variant) => ({
  name: `chromium-${variant.locale}-${variant.theme}`,
  ...(variant.grep ? { grep: variant.grep } : {}),
  use: {
    ...devices['Desktop Chrome'],
    viewport: { width: 1440, height: 900 },
    locale: browserLocale(variant.locale),
    colorScheme: variant.theme,
    appLocale: variant.locale,
    appTheme: variant.theme,
  },
}));

const mobile: Projects = [
  {
    name: 'chromium-mobile-ru-dark',
    grep: /@responsive/,
    use: {
      ...devices['Pixel 7'],
      locale: browserLocale('ru'),
      colorScheme: 'dark',
      appLocale: 'ru',
      appTheme: 'dark',
    },
  },
  {
    name: 'chromium-mobile-en-light',
    grep: /@responsive/,
    use: {
      ...devices['Pixel 7'],
      locale: browserLocale('en'),
      colorScheme: 'light',
      appLocale: 'en',
      appTheme: 'light',
    },
  },
];

export default defineConfig<ProjectOptions>({
  testDir: './specs',
  outputDir: './test-results',
  globalSetup: './global-setup.ts',
  workers: Number(process.env.E2E_WORKERS ?? 1),
  fullyParallel: false,
  forbidOnly: ci,
  retries: ci ? 1 : 0,
  timeout: 120_000,
  expect: { timeout: 15_000 },
  reporter: ci
    ? [['github'], ['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }], ['junit', { outputFile: 'test-results/junit.xml' }]]
    : [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
  use: {
    baseURL: cfg.baseURL,
    testIdAttribute: 'data-test',
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
    trace: ci ? 'on-first-retry' : 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    offline: false,
    serviceWorkers: 'block',
    contextOptions: { reducedMotion: 'reduce' },
  },
  testIgnore: stripeLive ? undefined : /stripe-live\.spec\.ts/,
  projects: stripeLive
    ? [
        {
          name: 'stripe-live',
          testMatch: /stripe-live\.spec\.ts/,
          use: {
            ...devices['Desktop Chrome'],
            viewport: { width: 1440, height: 900 },
            locale: browserLocale('en'),
            colorScheme: 'light',
            appLocale: 'en',
            appTheme: 'light',
          },
        },
      ]
    : [...desktop, ...mobile],
});
