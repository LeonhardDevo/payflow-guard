import { test as base, expect, type Browser, type BrowserContext, type Locator, type Page, type TestInfo } from '@playwright/test';
import { expectAccessible, type AxeOptions } from './support/a11y.ts';
import { captureScreenshot } from './support/artifacts.ts';
import { loadConfig, type E2EConfig } from './support/config.ts';
import { appRequest } from './support/http.ts';
import { Mailpit } from './support/mailpit.ts';
import { formatViolations, isExternalRequest, type ExternalRequest } from './support/network.ts';

export type AppLocale = 'en' | 'ru';
export type AppTheme = 'light' | 'dark';

export interface Account {
  name: string;
  email: string;
  password: string;
  workspace: string;
}

const cfg: E2EConfig = loadConfig();

export function uniqueId(testInfo: TestInfo): string {
  const project = testInfo.project.name.replace(/[^a-z0-9]+/gi, '').slice(-6).toLowerCase();
  return `${project}${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
}

export function submitButton(scope: Page | Locator, testId: string): Locator {
  return scope.getByTestId(testId).or(scope.locator('form button[type="submit"]')).first();
}

export function byTestIdAttr(scope: Page | Locator, testId: string, attr: string, value: string): Locator {
  return scope.locator(`[data-test="${testId}"][data-${attr}="${value.replace(/"/g, '\\"')}"]`);
}

export class AppHelpers {
  readonly page: Page;
  readonly testInfo: TestInfo;
  readonly locale: AppLocale;
  readonly theme: AppTheme;
  readonly mailpit: Mailpit;
  readonly config: E2EConfig;

  constructor(page: Page, testInfo: TestInfo, locale: AppLocale, theme: AppTheme, mailpit: Mailpit) {
    this.page = page;
    this.testInfo = testInfo;
    this.locale = locale;
    this.theme = theme;
    this.mailpit = mailpit;
    this.config = cfg;
  }

  async login(email: string, password: string = cfg.credentials.password): Promise<void> {
    const { page } = this;
    await page.goto('/login');
    await page.locator('input[name="email"]').fill(email);
    await page.locator('input[name="password"]').fill(password);
    await page.getByTestId('login-button').click();
    await expect(page).not.toHaveURL(/\/login(?:[?#]|$)/, { timeout: 20_000 });
  }

  loginAsDemo(): Promise<void> {
    return this.login(cfg.credentials.demo);
  }

  loginAsAdmin(): Promise<void> {
    return this.login(cfg.credentials.admin);
  }

  async register(prefix: string, email?: string): Promise<Account> {
    const { page } = this;
    const id = uniqueId(this.testInfo);
    const account: Account = {
      name: `E2E ${prefix} ${id}`,
      email: email ?? `e2e.${prefix}.${id}@${cfg.slug}.test`,
      password: `E2e!${id}Pw9`,
      workspace: '',
    };
    await page.goto('/register');
    await page.locator('input[name="name"]').fill(account.name);
    await page.locator('input[name="email"]').fill(account.email);
    await page.locator('input[name="password"]').fill(account.password);
    await page.locator('input[name="password_confirmation"]').fill(account.password);
    await this.acceptRegistrationConsents();
    await page.getByTestId('register-user-button').click();
    await expect(page).not.toHaveURL(/\/register(?:[?#]|$)/, { timeout: 20_000 });
    await this.verifyEmailIfRequired(account.email);
    return account;
  }

  async acceptRegistrationConsents(): Promise<void> {
    for (const id of ['register-accept-terms', 'register-accept-consent-ru']) {
      const box = this.page.getByTestId(id);
      if ((await box.count()) > 0 && (await box.getAttribute('aria-checked')) !== 'true') await box.click();
    }
  }

  async registerOwner(prefix = 'owner'): Promise<Account> {
    const account = await this.register(prefix);
    account.workspace = `E2E Studio ${uniqueId(this.testInfo)}`;
    await this.completeOnboarding(account.workspace);
    return account;
  }

  async verifyEmailIfRequired(email: string): Promise<void> {
    const { page } = this;
    await page.goto('/dashboard');
    if (!/\/(?:email\/verify|verify-email)(?:[/?#]|$)/.test(new URL(page.url()).pathname)) return;
    const link = await this.mailpit.waitForLink(email, /\/email\/verify\/|\/verify-email\//, 45_000);
    await page.goto(link);
  }

  async completeOnboarding(workspace: string): Promise<void> {
    const { page } = this;
    if (!/\/onboarding\/workspace/.test(page.url())) await page.goto('/onboarding/workspace');
    await page.locator('input[name="name"]').fill(workspace);
    const submit = page.getByTestId('onboarding-submit');
    const next = page.getByTestId('onboarding-next');
    const dpa = page.getByTestId('onboarding-accept-dpa');
    const acceptDpa = async (): Promise<void> => {
      if ((await dpa.isVisible()) && (await dpa.getAttribute('aria-checked')) !== 'true') await dpa.click();
    };
    for (let step = 0; step < 6 && !(await submit.isVisible()); step++) {
      await expect(next, 'onboarding: neither next nor submit is visible').toBeVisible();
      await acceptDpa();
      await next.click();
    }
    await acceptDpa();
    await submit.click();
    await page.waitForURL(/\/dashboard/, { timeout: 5_000 }).catch(() => undefined);
    if (await dpa.isVisible()) {
      await acceptDpa();
      await submit.click();
    }
    await expect(page).toHaveURL(/\/dashboard/, { timeout: 20_000 });
  }

  async inviteMember(email: string, role = 'member'): Promise<void> {
    const { page } = this;
    await page.goto('/settings/members');
    await page.getByTestId('invite-member-button').click();
    const dialog = page.getByRole('dialog');
    await dialog.locator('input[name="email"]').fill(email);
    const roleField = dialog.locator('select[name="role"]');
    if ((await roleField.count()) > 0) await roleField.selectOption(role, { force: true });
    await submitButton(dialog, 'invite-submit').click();
    await expect(byTestIdAttr(page, 'invitation-row', 'email', email)).toBeVisible();
  }

  async setLocale(locale: AppLocale): Promise<void> {
    if (!this.page.url().startsWith('http')) await this.page.goto('/');
    const response = await appRequest(this.page, `/locale/${locale}`, { method: 'POST' });
    expect(response.status(), `POST /locale/${locale}`).toBeLessThan(400);
    await this.page.reload();
    await this.expectLocale(locale);
  }

  async switchLocaleViaUi(locale: AppLocale): Promise<void> {
    const { page } = this;
    const persisted = page.waitForResponse(
      (response) => response.request().method() === 'POST' && new URL(response.url()).pathname === `/locale/${locale}`,
    );
    await page.getByTestId('locale-switcher').click();
    await page.getByTestId(`locale-option-${locale}`).click();
    const response = await persisted;
    expect(response.status(), `POST /locale/${locale}`).toBeLessThan(400);
    await this.expectLocale(locale);
  }

  async expectLocale(locale: AppLocale): Promise<void> {
    await expect(this.page.locator('html')).toHaveAttribute('lang', new RegExp(`^${locale}(?:-|$)`));
  }

  async setTheme(theme: AppTheme | 'system'): Promise<void> {
    await this.page.evaluate((value) => {
      localStorage.setItem('appearance', value);
      document.cookie = `appearance=${value};path=/;max-age=31536000;SameSite=Lax`;
    }, theme);
    await this.page.reload();
  }

  async expectTheme(theme: AppTheme): Promise<void> {
    const html = this.page.locator('html');
    if (theme === 'dark') await expect(html).toHaveClass(/(?:^|\s)dark(?:\s|$)/);
    else await expect(html).not.toHaveClass(/(?:^|\s)dark(?:\s|$)/);
  }

  shot(name: string, fullPage = true): Promise<string> {
    return captureScreenshot(this.page, this.testInfo, name, fullPage);
  }

  axe(name: string, options?: AxeOptions): Promise<void> {
    return expectAccessible(this.page, this.testInfo, name, options);
  }

  async expectNoHorizontalScroll(): Promise<void> {
    const overflow = await this.page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow, 'page is wider than the viewport').toBeLessThanOrEqual(1);
  }
}

export interface Session {
  context: BrowserContext;
  page: Page;
  app: AppHelpers;
}

interface ProjectOptions {
  appLocale: AppLocale;
  appTheme: AppTheme;
}

interface Fixtures {
  networkGuard: ExternalRequest[];
  hydrationGuard: string[];
  app: AppHelpers;
  mailpit: Mailpit;
  owner: Account;
  openSession: () => Promise<Session>;
}

async function applyPreferences(context: BrowserContext, theme: AppTheme): Promise<void> {
  await context.addCookies([{ name: 'appearance', value: theme, url: cfg.baseURL, sameSite: 'Lax' }]);
  await context.addInitScript((value) => {
    try {
      if (!window.localStorage.getItem('appearance')) window.localStorage.setItem('appearance', value);
    } catch {
      return;
    }
  }, theme);
}

async function installNetworkGuard(context: BrowserContext, sink: ExternalRequest[]): Promise<void> {
  await context.route(
    (url) => isExternalRequest(url.toString(), cfg.allowedHosts),
    async (route) => {
      const request = route.request();
      let frameUrl: string | null = null;
      try {
        frameUrl = request.frame().url();
      } catch {
        frameUrl = null;
      }
      sink.push({ url: request.url(), resourceType: request.resourceType(), frameUrl });
      await route.abort('blockedbyclient');
    },
  );
}

export const HYDRATION_MESSAGE = /hydration/i;

function installHydrationGuard(context: BrowserContext, sink: string[]): void {
  context.on('console', (message) => {
    const type = message.type();
    if ((type === 'warning' || type === 'error') && HYDRATION_MESSAGE.test(message.text())) {
      let url = '';
      try {
        url = message.page()?.url() ?? '';
      } catch {
        url = '';
      }
      sink.push(`[${type}] ${url} ${message.text().split('\n')[0]}`);
    }
  });
}

async function newSession(
  browser: Browser,
  testInfo: TestInfo,
  locale: AppLocale,
  theme: AppTheme,
  sink: ExternalRequest[],
  hydrationSink: string[],
): Promise<Session> {
  const use = testInfo.project.use;
  const context = await browser.newContext({
    baseURL: cfg.baseURL,
    locale: use.locale,
    colorScheme: use.colorScheme,
    viewport: use.viewport,
    serviceWorkers: 'block',
    reducedMotion: 'reduce',
  });
  await applyPreferences(context, theme);
  await installNetworkGuard(context, sink);
  installHydrationGuard(context, hydrationSink);
  const page = await context.newPage();
  return { context, page, app: new AppHelpers(page, testInfo, locale, theme, new Mailpit(cfg.mailpitURL)) };
}

export const test = base.extend<ProjectOptions & Fixtures>({
  appLocale: ['en', { option: true }],
  appTheme: ['light', { option: true }],

  context: async ({ context, appTheme }, use) => {
    await applyPreferences(context, appTheme);
    await use(context);
  },

  networkGuard: [
    async ({ context }, use, testInfo) => {
      const violations: ExternalRequest[] = [];
      await installNetworkGuard(context, violations);
      await use(violations);
      if (violations.length > 0) {
        await testInfo.attach('external-requests.json', { body: JSON.stringify(violations, null, 2), contentType: 'application/json' });
        throw new Error(formatViolations(violations));
      }
    },
    { auto: true },
  ],

  hydrationGuard: [
    async ({ context }, use, testInfo) => {
      const mismatches: string[] = [];
      installHydrationGuard(context, mismatches);
      await use(mismatches);
      if (mismatches.length > 0) {
        await testInfo.attach('hydration-mismatches.txt', { body: mismatches.join('\n'), contentType: 'text/plain' });
        throw new Error(`SSR hydration mismatch (${mismatches.length}):\n  ${mismatches.slice(0, 10).join('\n  ')}`);
      }
    },
    { auto: true },
  ],

  mailpit: async ({}, use) => {
    await use(new Mailpit(cfg.mailpitURL));
  },

  app: async ({ page, appLocale, appTheme, mailpit }, use, testInfo) => {
    await use(new AppHelpers(page, testInfo, appLocale, appTheme, mailpit));
  },

  owner: async ({ app }, use) => {
    const account = await app.registerOwner();
    await app.setLocale(app.locale);
    await use(account);
  },

  openSession: async ({ browser, appLocale, appTheme, networkGuard, hydrationGuard }, use, testInfo) => {
    const sessions: Session[] = [];
    await use(async () => {
      const session = await newSession(browser, testInfo, appLocale, appTheme, networkGuard, hydrationGuard);
      sessions.push(session);
      return session;
    });
    for (const session of sessions) await session.context.close();
  },
});

export { expect };
export const config = cfg;
