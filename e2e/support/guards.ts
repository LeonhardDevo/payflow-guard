import type { BrowserContext, ConsoleMessage, Page } from '@playwright/test';

export const EMOJI = /[\p{Extended_Pictographic}\u{1F1E6}-\u{1F1FF}\u{FE0F}]/u;

const ALLOWED_PICTOGRAPHS = new Set(['©', '®', '™', '↔', '↕', '↩', '↪', '▪', '▫', '►', '◄', '☐', '✓', '✔', '✕', '✖', '❯', '❮', '‼', '⁉', 'ℹ', '↗', '↘', '↙', '↖', '⌘', '⌫', '⏎', '★', '☆']);

export function emojiIn(text: string): string[] {
  const found = new Set<string>();
  for (const char of text) {
    if (EMOJI.test(char) && !ALLOWED_PICTOGRAPHS.has(char)) found.add(char);
  }
  return [...found];
}

export async function pageEmoji(page: Page): Promise<string[]> {
  const text = await page.evaluate(() => document.body?.innerText ?? '');
  return emojiIn(text);
}

export const RAW_KEY = /\b(?:pf|guard|ui|payflow|auth|billing|marketing|legal|notifications|errors|audit|validation|emails)\.[a-z0-9_]+(?:\.[a-z0-9_]+)+\b/g;

export function rawKeysIn(text: string): string[] {
  return [...new Set(text.match(RAW_KEY) ?? [])];
}

export async function overflowingControls(page: Page): Promise<string[]> {
  return page.evaluate(() => {
    const found: string[] = [];
    for (const element of document.querySelectorAll<HTMLElement>('button, a[href], [role="tab"], [role="radio"], th, label')) {
      const style = getComputedStyle(element);
      if (style.display === 'none' || style.visibility === 'hidden' || element.offsetParent === null) continue;
      if (element.clientWidth <= 1 && element.clientHeight <= 1) continue;
      if (style.textOverflow === 'ellipsis' || element.closest('[data-allow-overflow], .overflow-x-auto, [class*="overflow-x-auto"]')) continue;
      if (element.scrollWidth > element.clientWidth + 2 && element.clientWidth > 0) {
        found.push(`${element.tagName.toLowerCase()} "${(element.innerText || element.getAttribute('aria-label') || '').trim().slice(0, 60)}" ${element.scrollWidth}>${element.clientWidth}`);
      }
    }
    return found;
  });
}

export function isConsoleProblem(message: Pick<ConsoleMessage, 'type' | 'text'>): boolean {
  if (message.type() !== 'error') return false;
  const text = message.text();
  if (/Download the Vue Devtools|vue-devtools/i.test(text)) return false;
  if (/^Failed to load resource: the server responded with a status of (?:409|422|423) /.test(text)) return false;
  return true;
}

export function watchConsole(context: BrowserContext, sink: string[]): void {
  const attach = (page: Page): void => {
    page.on('console', (message) => {
      if (!isConsoleProblem(message)) return;
      sink.push(`[console] ${page.url()} ${message.text().split('\n')[0]}`);
    });
    page.on('pageerror', (error) => {
      sink.push(`[pageerror] ${page.url()} ${error.message.split('\n')[0]}`);
    });
  };
  for (const page of context.pages()) attach(page);
  context.on('page', attach);
}
