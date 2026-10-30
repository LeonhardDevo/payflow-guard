import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { RagLocale } from './i18n.js';

export const PROMPTS_DIR = fileURLToPath(new URL('../prompts/', import.meta.url));

const cache = new Map<string, string>();

function read(path: string): string {
  let text = cache.get(path);
  if (text === undefined) {
    text = readFileSync(path, 'utf8').replace(/<!--[\s\S]*?-->/g, '').trim();
    cache.set(path, text);
  }
  return text;
}

export function systemPrompt(locale: RagLocale, productName: string, today: Date = new Date(), domainPromptDir: string | null = null): string {
  const values: Record<string, string> = {
    product_name: productName,
    domain: read(join(domainPromptDir ?? PROMPTS_DIR, `domain.${locale}.md`)),
    today: today.toISOString().slice(0, 10),
  };
  return read(join(PROMPTS_DIR, `system.${locale}.md`)).replace(/\$(\w+)/g, (match, name: string) => values[name] ?? match);
}
