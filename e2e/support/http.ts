import type { APIResponse, Page } from '@playwright/test';

export async function xsrfToken(page: Page): Promise<string> {
  const cookies = await page.context().cookies();
  let cookie = cookies.find((c) => c.name === 'XSRF-TOKEN');
  if (!cookie) {
    await page.request.get('/up');
    cookie = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN');
  }
  if (!cookie) throw new Error('XSRF-TOKEN cookie missing — open a page of the app first');
  return decodeURIComponent(cookie.value);
}

export interface AppRequestOptions {
  method?: 'POST' | 'PUT' | 'PATCH' | 'DELETE' | 'GET';
  data?: unknown;
  followRedirects?: boolean;
}

export async function appRequest(page: Page, path: string, options: AppRequestOptions = {}): Promise<APIResponse> {
  return page.request.fetch(path, {
    method: options.method ?? 'POST',
    data: options.data as Record<string, unknown> | undefined,
    headers: {
      'X-XSRF-TOKEN': await xsrfToken(page),
      'X-Requested-With': 'XMLHttpRequest',
      Accept: 'application/json',
    },
    maxRedirects: options.followRedirects ? 20 : 0,
  });
}
