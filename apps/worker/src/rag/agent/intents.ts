import * as payflow from '../domain/payflow/index.js';
import type { IntentRule, MockPlanner } from '../providers/mock.js';
import type { InputGuard } from './pipeline.js';

export const FOUNDATION_RULES: readonly IntentRule[] = [
  {
    tool: 'search_documents',
    patterns: [
      /^(?:please\s+)?(?:find|search(?:\s+for)?|look\s+up)\s+(.{3,})$/iu,
      /^(?:пожалуйста\s+)?(?:найди|найти|поищи|ищи|поиск)\s+(.{3,})$/iu,
    ],
    buildArgs: (_question, match) => ({ query: (match[1] ?? '').trim().replace(/^[ ?.!]+|[ ?.!]+$/g, '') }),
  },
];

export const DOMAIN_RULES: readonly IntentRule[] = [];

export const INTENT_RULES: readonly IntentRule[] = [...DOMAIN_RULES, ...FOUNDATION_RULES];

export const DOMAIN_PLANNERS: readonly MockPlanner[] = [...payflow.PLANNERS];

export function domainInputGuards(settings: { publicWebUrl: string }): InputGuard[] {
  return payflow.inputGuards(settings);
}
