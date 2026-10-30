import { defineConfig, devices } from '@playwright/test';
import type { AppLocale, AppTheme } from './fixtures.ts';

interface ProjectOptions {
  appLocale: AppLocale;
  appTheme: AppTheme;
}

export default defineConfig<ProjectOptions>({
  testDir: './selftest',
  testMatch: /.*\.selftest\.ts$/,
  outputDir: './test-results/selftest',
  workers: 1,
  timeout: 30_000,
  reporter: [['list']],
  use: { trace: 'retain-on-failure', testIdAttribute: 'data-test' },
  projects: [
    {
      name: 'selftest-dark',
      use: { ...devices['Desktop Chrome'], colorScheme: 'dark', appLocale: 'en', appTheme: 'dark' },
    },
  ],
});
