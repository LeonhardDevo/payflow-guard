import { NOT_FOUND_MARKER, parseSources, type ParsedSource } from '../agent/sources.js';
import { contentTerms, coverage, estimateTokens, splitSentences } from '../text.js';
import { MOCK_MODEL } from './catalog.js';
import type { AiProvider, ChatMessage, ChatRequest, ChatResult, ToolCall } from './types.js';

export interface IntentStep {
  tool: string;
  args: Record<string, unknown>;
}

export interface ComposeInput {
  question: string;
  locale: 'ru' | 'en';
  sources: readonly ParsedSource[];
  dataNotes: readonly string[];
}

export interface IntentRule {
  tool: string;
  patterns: readonly RegExp[];
  buildArgs: (question: string, match: RegExpExecArray) => Record<string, unknown>;
  next?: (question: string, results: readonly string[]) => IntentStep | null;
  compose?: (input: ComposeInput) => string | null;
}

export interface PlannedStep {
  tool: string;
  args: Record<string, unknown>;
}

export interface MockPlan {
  intent: string;
  steps: readonly PlannedStep[];
  [key: string]: unknown;
}

export interface ToolOutcome {
  tool: string;
  content: string;
}

export interface PlanInput {
  question: string;
  locale: 'ru' | 'en';
  history: readonly string[];
}

export interface RenderInput {
  plan: MockPlan;
  question: string;
  locale: 'ru' | 'en';
  results: readonly ToolOutcome[];
  sources: readonly ParsedSource[];
}

export interface MockPlanner {
  plan(input: PlanInput): MockPlan | null;
  render(input: RenderInput): string | null;
}

const MAX_SENTENCES = 3;
const MIN_SENTENCE_COVERAGE = 0.25;

const INTRO = {
  en: 'According to the approved documents:',
  ru: 'Согласно утверждённым документам:',
} as const;

const DATA_INTRO = {
  en: 'Data from connected systems:',
  ru: 'Данные из подключённых систем:',
} as const;

interface Candidate {
  score: number;
  order: [number, number];
  ref: number;
  sentence: string;
}

export function matchIntent(rule: IntentRule, question: string): Record<string, unknown> | null {
  for (const pattern of rule.patterns) {
    pattern.lastIndex = 0;
    const found = pattern.exec(question);
    if (found) return rule.buildArgs(question, found);
  }
  return null;
}

export class MockProvider implements AiProvider {
  readonly id = 'mock';

  constructor(
    private readonly rules: readonly IntentRule[] = [],
    private readonly planners: readonly MockPlanner[] = [],
  ) {}

  async chat(request: ChatRequest): Promise<ChatResult> {
    const metadata = request.metadata ?? {};
    const promptTokens = request.messages.reduce((sum, message) => sum + estimateTokens(message.content), 0);
    if (metadata.ping === true) return this.result('OK', promptTokens);

    const turn = currentTurn(request.messages);
    const question = typeof metadata.question === 'string' ? metadata.question : (turn[0]?.content ?? '');
    const locale = typeof metadata.locale === 'string' && metadata.locale.startsWith('ru') ? 'ru' : 'en';
    const toolMessages = turn.filter((message) => message.role === 'tool');

    const offered = new Set((request.tools ?? []).map((tool) => tool.name));
    const planned = this.plan(question, locale, request.messages, offered);
    if (planned) {
      const executed = toolMessages.length;
      const step = planned.steps[executed];
      if (step) {
        const call: ToolCall = { id: `mock_${step.tool}_${executed + 1}`, name: step.tool, arguments: JSON.stringify(step.args) };
        return {
          content: null,
          toolCalls: [call],
          usage: { promptTokens, completionTokens: estimateTokens(call.arguments), cachedTokens: 0 },
          finishReason: 'tool_calls',
          servedModel: MOCK_MODEL,
        };
      }
      const rendered = planned.planner.render({
        plan: planned.plan,
        question,
        locale,
        results: toolOutcomes(turn),
        sources: turnSources(turn),
      });
      if (rendered !== null) return this.result(rendered, promptTokens);
    }
    const matched = this.match(question, offered);
    if (toolMessages.length === 0 || matched?.rule.next) {
      const call = toolMessages.length === 0 ? this.route(question, offered) : this.follow(matched, question, toolMessages, offered);
      if (call) {
        return {
          content: null,
          toolCalls: [call],
          usage: { promptTokens, completionTokens: estimateTokens(call.arguments), cachedTokens: 0 },
          finishReason: 'tool_calls',
          servedModel: MOCK_MODEL,
        };
      }
    }

    const sources: ParsedSource[] = [];
    const dataNotes: string[] = [];
    for (const message of turn) {
      if (message.role === 'user') sources.push(...parseSources(message.content));
      else if (message.role === 'tool') {
        const parsed = parseSources(message.content);
        if (parsed.length > 0) sources.push(...parsed);
        else {
          const note = dataSummary(message.content);
          if (note) dataNotes.push(note);
        }
      }
    }
    const custom = matched?.rule.compose?.({ question, locale, sources, dataNotes }) ?? null;
    return this.result(custom ?? compose(question, sources, dataNotes, locale), promptTokens);
  }

  private plan(
    question: string,
    locale: 'ru' | 'en',
    messages: readonly ChatMessage[],
    offered: Set<string>,
  ): { planner: MockPlanner; plan: MockPlan; steps: PlannedStep[] } | null {
    if (this.planners.length === 0) return null;
    const history = priorUserTurns(messages);
    for (const planner of this.planners) {
      const plan = planner.plan({ question: question.trim(), locale, history });
      if (plan) return { planner, plan, steps: plan.steps.filter((step) => offered.has(step.tool)) };
    }
    return null;
  }

  private match(question: string, offered: Set<string>): { rule: IntentRule; args: Record<string, unknown> } | null {
    for (const rule of this.rules) {
      if (!offered.has(rule.tool)) continue;
      const args = matchIntent(rule, question.trim());
      if (args) return { rule, args };
    }
    return null;
  }

  private follow(matched: { rule: IntentRule } | null, question: string, toolMessages: readonly ChatMessage[], offered: Set<string>): ToolCall | null {
    const step = matched?.rule.next?.(question, toolMessages.map((message) => message.content)) ?? null;
    if (!step || !offered.has(step.tool)) return null;
    return { id: `mock_${step.tool}_${toolMessages.length + 1}`, name: step.tool, arguments: JSON.stringify(step.args) };
  }

  private route(question: string, offered: Set<string>): ToolCall | null {
    for (const rule of this.rules) {
      if (!offered.has(rule.tool)) continue;
      const args = matchIntent(rule, question.trim());
      if (args) return { id: `mock_${rule.tool}_1`, name: rule.tool, arguments: JSON.stringify(args) };
    }
    return null;
  }

  private result(text: string, promptTokens: number): ChatResult {
    return {
      content: text,
      toolCalls: [],
      usage: { promptTokens, completionTokens: estimateTokens(text), cachedTokens: 0 },
      finishReason: 'stop',
      servedModel: MOCK_MODEL,
    };
  }
}

function currentTurn(messages: readonly ChatMessage[]): ChatMessage[] {
  for (let index = messages.length - 1; index >= 0; index--) {
    if (messages[index]?.role === 'user') return messages.slice(index);
  }
  return [];
}

function priorUserTurns(messages: readonly ChatMessage[]): string[] {
  let last = -1;
  for (let index = messages.length - 1; index >= 0; index--) {
    if (messages[index]?.role === 'user') {
      last = index;
      break;
    }
  }
  return messages.slice(0, Math.max(last, 0)).filter((message) => message.role === 'user').map((message) => message.content);
}

function toolOutcomes(turn: readonly ChatMessage[]): ToolOutcome[] {
  const names = new Map<string, string>();
  for (const message of turn) for (const call of message.toolCalls ?? []) names.set(call.id, call.name);
  return turn
    .filter((message) => message.role === 'tool')
    .map((message) => ({ tool: names.get(message.toolCallId ?? '') ?? '', content: unwrapToolResult(message.content) }));
}

function turnSources(turn: readonly ChatMessage[]): ParsedSource[] {
  const sources: ParsedSource[] = [];
  for (const message of turn) if (message.role === 'user' || message.role === 'tool') sources.push(...parseSources(message.content));
  return sources;
}

export function unwrapToolResult(raw: string): string {
  return raw.replace(/^\s*<tool_result[^>]*>\s*/, '').replace(/\s*<\/tool_result>\s*$/, '');
}

function dataSummary(raw: string): string | null {
  try {
    const payload: unknown = JSON.parse(unwrapToolResult(raw));
    if (payload && typeof payload === 'object' && 'summary' in payload) {
      const summary = payload.summary;
      if (typeof summary === 'string' && summary.trim() !== '') return summary.trim();
      if (typeof summary === 'number') return String(summary);
    }
  } catch {
    return null;
  }
  return null;
}

function compose(question: string, sources: readonly ParsedSource[], dataNotes: readonly string[], locale: 'ru' | 'en'): string {
  const terms = contentTerms(question);
  const candidates: Candidate[] = [];
  const seen = new Set<string>();
  sources.forEach((source, sourceIndex) => {
    splitSentences(source.content).forEach((sentence, sentenceIndex) => {
      const key = sentence.toLowerCase();
      if (seen.has(key) || sentence.length < 3) return;
      seen.add(key);
      const score = terms.length > 0 ? coverage(terms, `${source.title} ${sentence}`) : 0;
      if (score >= MIN_SENTENCE_COVERAGE) candidates.push({ score, order: [sourceIndex, sentenceIndex], ref: source.ref, sentence });
    });
  });
  const byOrder = (a: Candidate, b: Candidate): number => a.order[0] - b.order[0] || a.order[1] - b.order[1];
  candidates.sort((a, b) => b.score - a.score || byOrder(a, b));
  const picked = candidates.slice(0, MAX_SENTENCES).sort(byOrder);

  const parts: string[] = [];
  if (dataNotes.length > 0) parts.push(`${DATA_INTRO[locale]}\n\n${dataNotes.map((note) => `- ${note}`).join('\n')}`);
  if (picked.length > 0) {
    const lines = picked.map((candidate) => `${candidate.sentence.trimEnd()} [${candidate.ref}]`);
    const body = lines.length === 1 ? lines[0] : lines.map((line) => `- ${line}`).join('\n');
    parts.push(`${INTRO[locale]}\n\n${body}`);
  }
  return parts.length > 0 ? parts.join('\n\n') : NOT_FOUND_MARKER;
}
