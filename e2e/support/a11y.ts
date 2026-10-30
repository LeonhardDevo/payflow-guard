import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, type TestInfo } from '@playwright/test';

export const BLOCKING_IMPACTS = ['serious', 'critical'] as const;
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

export interface AxeOptions {
  exclude?: string[];
  disableRules?: string[];
  soft?: boolean;
}

interface ViolationNode {
  target: unknown[];
  failureSummary?: string;
}

interface Violation {
  id: string;
  impact?: string | null;
  help: string;
  helpUrl: string;
  nodes: ViolationNode[];
}

export function formatViolations(violations: readonly Violation[]): string {
  return violations
    .map((violation) => {
      const nodes = violation.nodes
        .slice(0, 5)
        .map((node) => `      ${node.target.join(' ')}${node.failureSummary ? `\n        ${node.failureSummary.replace(/\n/g, '\n        ')}` : ''}`)
        .join('\n');
      return `  [${violation.impact}] ${violation.id}: ${violation.help} (${violation.nodes.length} node(s))\n    ${violation.helpUrl}\n${nodes}`;
    })
    .join('\n');
}

export function blocking<T extends { impact?: string | null }>(violations: readonly T[]): T[] {
  return violations.filter((violation) => (BLOCKING_IMPACTS as readonly string[]).includes(violation.impact ?? ''));
}

export async function expectAccessible(page: Page, testInfo: TestInfo, name: string, options: AxeOptions = {}): Promise<void> {
  await page.waitForLoadState('networkidle').catch(() => undefined);
  await page.evaluate(() => document.fonts?.ready).catch(() => undefined);

  let builder = new AxeBuilder({ page }).withTags(WCAG_TAGS);
  for (const selector of options.exclude ?? []) builder = builder.exclude(selector);
  if (options.disableRules?.length) builder = builder.disableRules(options.disableRules);

  const results = await builder.analyze();
  await testInfo.attach(`axe-${name}.json`, { body: JSON.stringify(results, null, 2), contentType: 'application/json' });

  const serious = blocking(results.violations as Violation[]);
  const assertion = options.soft ? expect.soft : expect;
  assertion(serious, `axe found serious/critical violations on ${name} (${page.url()}):\n${formatViolations(serious)}`).toEqual([]);
}
