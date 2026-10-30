import type { Logger } from '../../logger.js';
import type { EmbeddingsRegistry } from '../embeddings/registry.js';
import type { RagMetrics } from '../metrics.js';
import { getEntry, isLocalProvider } from '../providers/catalog.js';
import type { AiManager } from '../providers/manager.js';
import { ProviderError, type AiProvider, type ChatMessage } from '../providers/types.js';
import type { HybridRetriever, Viewer } from '../retrieval/hybrid.js';
import { contentTerms } from '../text.js';
import { sourceFromHit } from '../tools/searchDocuments.js';
import type { ChatAction, ChatUser, ToolContext, ToolRegistry } from '../tools/types.js';
import type { AiQueryPayload, RagWebApi } from '../web/client.js';
import { conflictNote, normalizeLocale, t, type RagLocale } from './i18n.js';
import { AgentLoop, type LoopOutcome } from './loop.js';
import type { ConversationMemory, Turn } from './memory.js';
import { systemPrompt } from './prompts.js';
import { citationPayload, markdownToHtml, renumberCitations, type CitationPayload } from './render.js';
import { NOT_FOUND_MARKER, renderDocuments, SourcePool } from './sources.js';

export type Channel = 'telegram' | 'web';
export type RefusalReason = 'low_confidence' | 'not_found' | 'model_refusal' | 'unavailable' | 'policy';

export interface InputGuardContext {
  input: ChatInput;
  question: string;
  locale: RagLocale;
  history: readonly Turn[];
}

export interface InputGuardOutcome {
  refused: boolean;
  answer: string;
  actions: ChatAction[];
  code: string;
}

export type InputGuard = (ctx: InputGuardContext) => InputGuardOutcome | null;

const FOLLOW_UP_MAX_TERMS = 4;
const FALLBACK_CITATIONS = 2;
const MARKER_RE = /\s?\[\d{1,3}\]/g;

export interface ChatInput {
  workspaceId: number;
  user: ChatUser;
  channel: Channel;
  message: string;
  conversationId: string | null;
  allowExternal?: boolean | null;
  requestId?: string | null;
}

export interface ChatOutput {
  answer_markdown: string;
  answer_html: string;
  citations: CitationPayload[];
  confidence: number;
  refused: boolean;
  tools_used: string[];
  actions: ChatAction[];
  provider: string;
  model: string;
  latency_ms: number;
  conversation_id: string | null;
  degraded: boolean;
  refusal_reason: RefusalReason | null;
  external_blocked: boolean;
}

export interface OutputGuardContext {
  input: ChatInput;
  locale: RagLocale;
  pool: SourcePool;
  outcome: LoopOutcome | null;
}

export type OutputGuard = (output: ChatOutput, ctx: OutputGuardContext) => ChatOutput | Promise<ChatOutput>;

export interface ChatServiceSettings {
  productName: string;
  publicWebUrl: string;
  minConfidence: number;
  topK: number;
  privilegedRoles: readonly string[];
  maxIterations: number;
  embeddingsLoadWaitMs: number;
  externalDefault: boolean;
  localLlmProvider: 'mock' | 'ollama';
  domainPromptDir: string | null;
}

export interface ChatServiceDeps {
  settings: ChatServiceSettings;
  manager: AiManager;
  embeddings: EmbeddingsRegistry;
  retriever: HybridRetriever;
  memory: ConversationMemory;
  registry: ToolRegistry;
  web: RagWebApi;
  logger: Logger;
  metrics?: RagMetrics;
  outputGuards?: OutputGuard[];
  inputGuards?: InputGuard[];
}

interface FinishOptions {
  confidence: number;
  provider: string;
  model: string;
  answer?: string;
  citations?: CitationPayload[];
  refusal?: RefusalReason | null;
  refusalActions?: ChatAction[];
  outcome?: LoopOutcome | null;
  degraded?: boolean;
  externalBlocked: boolean;
  pool: SourcePool;
}

export class ChatService {
  readonly outputGuards: OutputGuard[];
  private readonly loop: AgentLoop;
  private readonly pendingLogs = new Set<Promise<void>>();

  constructor(private readonly deps: ChatServiceDeps) {
    this.outputGuards = deps.outputGuards ?? [];
    this.loop = new AgentLoop(deps.manager, deps.settings.maxIterations, deps.logger);
  }

  async answer(input: ChatInput): Promise<ChatOutput> {
    const started = performance.now();
    const { settings, manager, retriever, memory, registry } = this.deps;
    const locale = normalizeLocale(input.user.locale);
    const question = input.message.trim();
    const history = await memory.load(input.workspaceId, input.conversationId, input.user.id);
    for (const guard of this.deps.inputGuards ?? []) {
      const verdict = guard({ input, question, locale, history });
      if (!verdict) continue;
      this.deps.logger.info({ code: verdict.code, refused: verdict.refused }, 'chat answered by an input guard');
      return this.finish(input, locale, started, {
        confidence: verdict.refused ? 0 : 1,
        provider: 'guard',
        model: 'rules',
        answer: verdict.answer,
        refusal: verdict.refused ? 'policy' : null,
        refusalActions: verdict.actions,
        externalBlocked: false,
        pool: new SourcePool(),
      });
    }
    const ai = await manager.currentSettings();
    const allowExternal = input.allowExternal ?? settings.externalDefault;
    const externalBlocked = !allowExternal && !isLocalProvider(ai.provider);
    const localProvider = externalBlocked ? await this.localProvider() : undefined;
    const activeProvider = localProvider ? localProvider.id : ai.provider;
    const activeModel = localProvider ? defaultModel(localProvider.id) : ai.model;
    const embedder = await this.deps.embeddings.active(settings.embeddingsLoadWaitMs, { externalAllowed: allowExternal });
    const viewer: Viewer = { roles: [input.user.role], privileged: settings.privilegedRoles.includes(input.user.role) };
    const retrieval = await retriever.search({
      workspaceId: input.workspaceId,
      query: retrievalQuery(question, history),
      embedder,
      viewer,
      k: settings.topK,
      locale,
    });
    const pool = new SourcePool();
    for (const hit of retrieval.hits) pool.add(sourceFromHit(hit, 'retrieval'));

    const tools = registry.availableFor(input.user);

    if (retrieval.confidence < settings.minConfidence && !tools.some((tool) => tool.dataTool)) {
      return this.finish(input, locale, started, {
        refusal: 'low_confidence',
        confidence: retrieval.confidence,
        provider: activeProvider,
        model: activeModel,
        degraded: externalBlocked,
        externalBlocked,
        pool,
      });
    }

    const ctx: ToolContext = {
      workspaceId: input.workspaceId,
      user: input.user,
      viewer,
      retriever,
      embedder,
      web: this.deps.web,
      sources: pool,
      topK: settings.topK,
      publicWebUrl: settings.publicWebUrl,
      requestId: input.requestId ?? null,
      locale,
      logger: this.deps.logger,
    };
    const metadata = { locale, question, workspace_id: input.workspaceId };
    let degraded = externalBlocked;
    let outcome: LoopOutcome;
    try {
      outcome = await this.loop.run(this.messages(locale, history, pool, question), tools, ctx, {
        metadata,
        allowExternal,
        ...(localProvider ? { provider: localProvider } : {}),
      });
    } catch (error) {
      if (!(error instanceof ProviderError)) throw error;
      this.deps.logger.warn({ error: error.message }, 'chat provider failed; degrading to the offline provider');
      degraded = true;
      try {
        outcome = await this.loop.run(this.messages(locale, history, pool, question), tools, ctx, {
          metadata,
          provider: manager.factory.mock,
        });
      } catch (fallbackError) {
        if (!(fallbackError instanceof ProviderError)) throw fallbackError;
        return this.finish(input, locale, started, {
          refusal: 'unavailable',
          confidence: 0,
          provider: activeProvider,
          model: activeModel,
          degraded: true,
          externalBlocked,
          pool,
        });
      }
    }

    const confidence = Math.max(retrieval.confidence, outcome.toolConfidence);
    const text = (outcome.text ?? '').trim();
    const common = { provider: outcome.provider, model: outcome.model, outcome, degraded, externalBlocked, pool };
    if (outcome.finishReason === 'refusal') return this.finish(input, locale, started, { ...common, refusal: 'model_refusal', confidence });
    if (text === '' || text.includes(NOT_FOUND_MARKER)) return this.finish(input, locale, started, { ...common, refusal: 'not_found', confidence });
    if (confidence < settings.minConfidence && !outcome.toolData) {
      return this.finish(input, locale, started, { ...common, refusal: 'low_confidence', confidence });
    }

    const renumbered = renumberCitations(text, pool);
    let answer = renumbered.answer;
    let citations = renumbered.cited.map((cited) => citationPayload(cited, question, settings.publicWebUrl));
    if (citations.length === 0 && !outcome.toolData) {
      citations = [...pool.sources]
        .sort((a, b) => b.confidence - a.confidence)
        .slice(0, FALLBACK_CITATIONS)
        .map((source, index) => citationPayload({ number: index + 1, source }, question, settings.publicWebUrl));
    }
    const conflicts = retrieval.conflicts.filter((conflict) => citations.some((citation) => citation.document_id === conflict.winnerDocumentId));
    if (conflicts.length > 0) answer = `${answer}\n\n${conflictNote(conflicts, locale)}`;
    return this.finish(input, locale, started, { ...common, answer, citations, confidence });
  }

  private async localProvider(): Promise<AiProvider> {
    if (this.deps.settings.localLlmProvider === 'ollama') {
      const ollama = await this.deps.manager.factory.build('ollama');
      if (ollama) return ollama;
    }
    return this.deps.manager.factory.mock;
  }

  private messages(locale: RagLocale, history: readonly Turn[], pool: SourcePool, question: string): ChatMessage[] {
    const messages: ChatMessage[] = [
      { role: 'system', content: systemPrompt(locale, this.deps.settings.productName, new Date(), this.deps.settings.domainPromptDir) },
    ];
    for (const turn of history) {
      messages.push({ role: turn.role, content: turn.role === 'assistant' ? turn.content.replace(MARKER_RE, '') : turn.content });
    }
    messages.push({ role: 'user', content: `${renderDocuments(pool.sources)}\n\n${t('question', locale)}: ${question}` });
    return messages;
  }

  private async finish(input: ChatInput, locale: RagLocale, started: number, options: FinishOptions): Promise<ChatOutput> {
    const actions: ChatAction[] = [...(options.outcome?.actions ?? [])];
    let answer = options.answer ?? '';
    let citations = options.citations ?? [];
    const refusal = options.refusal ?? null;
    if (refusal === 'policy') {
      citations = [];
      actions.push(...(options.refusalActions ?? []));
    } else if (refusal === null && options.refusalActions) {
      actions.push(...options.refusalActions);
    } else if (refusal !== null) {
      answer = t(refusal === 'model_refusal' ? 'model_refusal' : refusal === 'unavailable' ? 'unavailable' : 'refusal', locale);
      citations = [];
      actions.push({
        type: 'escalate',
        label: t('escalate', locale),
        payload: { reason: refusal, question: input.message.trim(), conversation_id: input.conversationId },
      });
    }
    let output: ChatOutput = {
      answer_markdown: answer,
      answer_html: markdownToHtml(answer),
      citations,
      confidence: Math.round(options.confidence * 10_000) / 10_000,
      refused: refusal !== null,
      tools_used: [...(options.outcome?.toolsUsed ?? [])],
      actions,
      provider: options.provider,
      model: options.model,
      latency_ms: 0,
      conversation_id: input.conversationId,
      degraded: options.degraded ?? false,
      refusal_reason: refusal,
      external_blocked: options.externalBlocked,
    };
    for (const guard of this.outputGuards) {
      output = await guard(output, { input, locale, pool: options.pool, outcome: options.outcome ?? null });
    }
    output.latency_ms = Math.round(performance.now() - started);
    try {
      await this.deps.memory.append(input.workspaceId, input.conversationId, input.user.id, input.message.trim(), output.answer_markdown);
    } catch (error) {
      this.deps.logger.warn({ error: error instanceof Error ? error.message : String(error) }, 'conversation memory could not be written');
    }
    this.deps.metrics?.chatAnswers.inc({ channel: input.channel, refused: String(output.refused), degraded: String(output.degraded) });
    this.scheduleQueryLog(input, output);
    return output;
  }

  private scheduleQueryLog(input: ChatInput, output: ChatOutput): void {
    const payload = queryLogPayload(input, output);
    const task = this.deps.web
      .logAiQuery(payload, input.requestId ?? undefined)
      .catch((error: unknown) => this.deps.logger.warn({ error: error instanceof Error ? error.message : String(error) }, 'ai query log failed'))
      .finally(() => this.pendingLogs.delete(task));
    this.pendingLogs.add(task);
  }

  async flush(): Promise<void> {
    await Promise.all([...this.pendingLogs]);
  }
}

function defaultModel(providerId: string): string {
  return getEntry(providerId)?.defaultModel ?? providerId;
}

export function retrievalQuery(question: string, history: readonly Turn[]): string {
  if (contentTerms(question).length > FOLLOW_UP_MAX_TERMS) return question;
  const previous = [...history].reverse().find((turn) => turn.role === 'user')?.content;
  return previous ? `${previous} ${question}` : question;
}

export function queryLogPayload(input: ChatInput, output: ChatOutput): AiQueryPayload {
  return {
    workspace_id: input.workspaceId,
    user_id: input.user.id,
    channel: input.channel,
    conversation_id: input.conversationId,
    question: input.message.trim(),
    answer: output.answer_markdown,
    refused: output.refused,
    confidence: output.confidence,
    citations: output.citations.map((citation) => ({ document_id: citation.document_id, title: citation.title, page: citation.page })),
    tools_used: output.tools_used,
    latency_ms: output.latency_ms,
    provider: output.provider,
    model: output.model,
  };
}
