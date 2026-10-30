import type pg from 'pg';
import type { Registry } from 'prom-client';
import type { Logger } from '../logger.js';
import { usageReport, type UsageReport } from './admin/usage.js';
import { normalizeLocale } from './agent/i18n.js';
import { ConversationMemory } from './agent/memory.js';
import { ChatService, type ChatOutput, type InputGuard, type OutputGuard } from './agent/pipeline.js';
import type { RagConfig } from './config.js';
import { EMBEDDING_PROVIDERS } from './config.js';
import { secretBoxFor } from './crypto.js';
import { migrateRag } from './db/migrations.js';
import { RagDb } from './db/ragDb.js';
import { EmbeddingsRegistry, type EmbeddingsPeek } from './embeddings/registry.js';
import type { ExtractorLoader } from './embeddings/local.js';
import { Chunker } from './ingest/chunker.js';
import { TesseractOcr, type OcrEngine } from './ingest/ocr.js';
import { DocumentParser } from './ingest/parsers.js';
import { IsolatedDocumentParser, type DocumentParsing } from './ingest/sandbox.js';
import { IngestService, type IngestOutcome } from './ingest/service.js';
import { RagMetrics } from './metrics.js';
import { PrivacyPurger, type WorkspacePurgeCounts, type WorkspaceRetention } from './privacy/purge.js';
import { DOMAIN_PLANNERS, domainInputGuards, INTENT_RULES } from './agent/intents.js';
import { CATALOG, KEYLESS_PROVIDERS, getEntry, localizedNote } from './providers/catalog.js';
import { ProviderFactory, type ProviderBuilder } from './providers/factory.js';
import { ProviderKeys, type KeySource } from './providers/keys.js';
import { LiveModels, type AnthropicLister, type LiveModel } from './providers/liveModels.js';
import { AiManager, PgUsageRecorder, type UsageRecorder } from './providers/manager.js';
import type { IntentRule, MockPlanner } from './providers/mock.js';
import { ProviderError } from './providers/types.js';
import { HybridRetriever, chunkToJson, type PostFusionHook, type SearchFilters } from './retrieval/hybrid.js';
import { ChunkStore, type ChunkPreview, type DocumentMeta } from './retrieval/store.js';
import { AiSettingsService, PgSettingsStore, type AiSettings, type AiSettingsPatch, type RawSettingsStore } from './settings/store.js';
import { buildRegistry, DOMAIN_PROMPTS_DIR, type ToolRegistrar } from './tools/index.js';
import { ChatUser } from './tools/types.js';
import { RagWebClient, type RagWebApi } from './web/client.js';

export interface RagServiceOptions {
  config: RagConfig;
  productName: string;
  publicWebUrl: string;
  webUrl: string;
  webAiKey: string;
  sharedSecret: string;
  isProduction: boolean;
  webTimeoutMs: number;
  pool: pg.Pool;
  logger: Logger;
  metricsRegistry?: Registry;
  fetch?: typeof fetch;
  web?: RagWebApi;
  ocr?: OcrEngine;
  parser?: DocumentParsing;
  domainPromptDir?: string | null;
  settingsStore?: RawSettingsStore;
  recorder?: UsageRecorder;
  providerBuilder?: ProviderBuilder;
  intentRules?: readonly IntentRule[];
  toolRegistrars?: readonly ToolRegistrar[];
  outputGuards?: OutputGuard[];
  inputGuards?: InputGuard[];
  planners?: readonly MockPlanner[];
  postFusion?: PostFusionHook;
  localLoader?: ExtractorLoader;
  anthropicLister?: AnthropicLister;
  sleep?: (ms: number) => Promise<void>;
}

export interface ChatBody {
  workspace_id: number;
  user: { id: number | null; role: string; locale: string; permissions: string[] };
  channel: 'telegram' | 'web';
  conversation_id?: string | null;
  message: string;
  ai_external_allowed?: boolean | null;
}

export interface IngestBody {
  workspace_id: number;
  title: string;
  mime: string;
  metadata: { department: string | null; roles: string[]; tags: string[]; version: string | null; effective_date: string | null };
  ai_external_allowed?: boolean | null;
}

export interface ProviderPayload {
  id: string;
  label: string;
  configured: boolean;
  needs_key: boolean;
  key_source: KeySource;
  key_hint: string | null;
  default_model: string;
  models: string[];
  note?: string;
}

export interface SettingsPayload extends AiSettings {
  embeddings_status: EmbeddingsPeek;
  embeddings_providers: string[];
  reembed_required?: boolean;
}

export class RagService {
  readonly db: RagDb;
  readonly metrics: RagMetrics;
  readonly settings: AiSettingsService;
  readonly keys: ProviderKeys;
  readonly manager: AiManager;
  readonly liveModels: LiveModels;
  readonly embeddings: EmbeddingsRegistry;
  readonly retriever: HybridRetriever;
  readonly store: ChunkStore;
  readonly memory: ConversationMemory;
  readonly chatService: ChatService;
  readonly ingestService: IngestService;
  readonly web: RagWebApi;
  readonly privacy: PrivacyPurger;

  get localLlmProvider(): 'mock' | 'ollama' {
    return this.options.config.ai.localLlmProvider;
  }

  constructor(private readonly options: RagServiceOptions) {
    const { config, logger } = options;
    this.db = new RagDb(options.pool, config.schema);
    this.metrics = new RagMetrics(options.metricsRegistry);
    const rawStore = options.settingsStore ?? new PgSettingsStore(this.db);
    this.settings = new AiSettingsService(rawStore, config);
    const box = secretBoxFor(config.settingsKey, options.sharedSecret, options.isProduction);
    this.keys = new ProviderKeys(rawStore, config.providerKeys, box, logger);
    const builder =
      options.providerBuilder ??
      new ProviderFactory(config, this.keys, options.intentRules ?? INTENT_RULES, {
        planners: options.planners ?? DOMAIN_PLANNERS,
        ...(options.fetch ? { fetch: options.fetch } : {}),
      });
    this.manager = new AiManager(this.settings, builder, options.recorder ?? new PgUsageRecorder(this.db), {
      retryBaseMs: config.ai.retryBaseMs,
      retryMaxMs: config.ai.retryMaxMs,
      maxTokens: config.ai.maxTokens,
      logger,
      metrics: this.metrics,
      ...(options.sleep ? { sleep: options.sleep } : {}),
    });
    this.liveModels = new LiveModels(config, this.keys, {
      ...(options.fetch ? { fetch: options.fetch } : {}),
      ...(options.anthropicLister ? { anthropicLister: options.anthropicLister } : {}),
    });
    this.embeddings = new EmbeddingsRegistry(config, this.settings, this.keys, logger, {
      ...(options.fetch ? { fetch: options.fetch } : {}),
      ...(options.localLoader ? { localLoader: options.localLoader } : {}),
    });
    this.retriever = new HybridRetriever(this.db, {
      candidates: config.rag.candidates,
      rrfK: config.rag.rrfK,
      postFusion: options.postFusion ?? null,
      metrics: this.metrics,
    });
    this.store = new ChunkStore(this.db, logger);
    this.memory = new ConversationMemory(this.db, config.rag.memoryTurns);
    this.web =
      options.web ??
      new RagWebClient(
        {
          service: 'web',
          caller: 'ai',
          baseUrl: options.webUrl,
          secret: options.webAiKey,
          timeoutMs: options.webTimeoutMs,
          ...(options.fetch ? { fetch: options.fetch } : {}),
        },
        config.rag.maxFileBytes,
      );
    this.chatService = new ChatService({
      settings: {
        productName: options.productName,
        publicWebUrl: options.publicWebUrl,
        minConfidence: config.rag.minConfidence,
        topK: config.rag.topK,
        privilegedRoles: config.rag.privilegedRoles,
        maxIterations: config.rag.maxIterations,
        embeddingsLoadWaitMs: config.embeddings.loadWaitMs,
        externalDefault: config.rag.externalDefault,
        localLlmProvider: config.ai.localLlmProvider,
        domainPromptDir: options.domainPromptDir === undefined ? DOMAIN_PROMPTS_DIR : options.domainPromptDir,
      },
      manager: this.manager,
      embeddings: this.embeddings,
      retriever: this.retriever,
      memory: this.memory,
      registry: buildRegistry(options.toolRegistrars ?? []),
      web: this.web,
      logger,
      metrics: this.metrics,
      outputGuards: options.outputGuards ?? [],
      inputGuards: options.inputGuards ?? domainInputGuards({ publicWebUrl: options.publicWebUrl }),
    });
    this.privacy = new PrivacyPurger(this.db);
    this.ingestService = new IngestService({
      web: this.web,
      parser: options.parser ?? this.buildParser(),
      chunker: new Chunker(config.rag.chunkTokens, config.rag.chunkOverlap),
      embeddings: this.embeddings,
      store: this.store,
      logger,
      metrics: this.metrics,
    });
  }

  private buildParser(): DocumentParsing {
    const { config, logger } = this.options;
    if (config.parse.isolated && !this.options.ocr) {
      return new IsolatedDocumentParser({
        ocr: {
          command: config.ocr.command,
          languages: config.ocr.languages,
          timeoutMs: config.ocr.timeoutMs,
          tessdataPrefix: config.ocr.tessdataPrefix ?? null,
          maxPages: config.ocr.maxPages,
        },
        timeoutMs: config.parse.timeoutMs,
        maxOldSpaceMb: config.parse.maxOldSpaceMb,
        logger,
      });
    }
    const ocr =
      this.options.ocr ??
      new TesseractOcr({
        command: config.ocr.command,
        languages: config.ocr.languages,
        timeoutMs: config.ocr.timeoutMs,
        tessdataPrefix: config.ocr.tessdataPrefix,
        logger,
      });
    return new DocumentParser({ ocr, logger, maxOcrPages: config.ocr.maxPages });
  }

  async migrate(): Promise<string[]> {
    return migrateRag(this.options.pool, this.options.logger, this.db.schema);
  }

  warmUp(): void {
    void this.embeddings
      .status()
      .then((status) => this.options.logger.info({ model: status.active, degraded: status.degraded }, 'embeddings ready'))
      .catch((error: unknown) => this.options.logger.warn({ error: String(error) }, 'embeddings warm-up failed'));
    void this.memory
      .prune()
      .then((rows) => {
        if (rows > 0) this.options.logger.info({ rows }, 'conversation memory pruned');
      })
      .catch((error: unknown) => this.options.logger.warn({ error: String(error) }, 'conversation memory prune failed'));
  }

  async chat(body: ChatBody, requestId?: string): Promise<ChatOutput> {
    const locale = normalizeLocale(body.user.locale);
    return this.chatService.answer({
      workspaceId: body.workspace_id,
      user: new ChatUser(body.user.id, body.user.role, locale, body.user.permissions),
      channel: body.channel,
      message: body.message,
      conversationId: body.conversation_id ?? null,
      allowExternal: body.ai_external_allowed ?? null,
      requestId: requestId ?? null,
    });
  }

  async search(body: {
    workspace_id: number;
    query: string;
    k: number;
    filters?: (SearchFilters & { roles?: string[] | null }) | null;
    ai_external_allowed?: boolean | null;
  }): Promise<Record<string, unknown>> {
    const filters = body.filters ?? null;
    const viewer =
      filters?.roles !== undefined && filters.roles !== null ? { roles: filters.roles, privileged: false } : { roles: [], privileged: true };
    const embedder = await this.embeddings.active(this.options.config.embeddings.loadWaitMs, {
      externalAllowed: body.ai_external_allowed ?? this.options.config.rag.externalDefault,
    });
    const result = await this.retriever.search({
      workspaceId: body.workspace_id,
      query: body.query,
      embedder,
      viewer,
      k: body.k,
      filters: {
        department: filters?.department ?? null,
        tags: filters?.tags ?? [],
        documentIds: filters?.documentIds ?? [],
      },
      resolve: false,
    });
    return {
      query: body.query,
      embedding_model: result.embeddingModel,
      confidence: Math.round(result.confidence * 10_000) / 10_000,
      min_confidence: this.options.config.rag.minConfidence,
      latency_ms: result.latencyMs,
      results: result.hits.map(chunkToJson),
    };
  }

  async ingest(documentId: number, body: IngestBody, requestId?: string): Promise<IngestOutcome> {
    const meta: DocumentMeta = {
      documentId,
      workspaceId: body.workspace_id,
      title: body.title.trim(),
      mime: body.mime,
      department: body.metadata.department,
      roles: body.metadata.roles,
      tags: body.metadata.tags,
      version: body.metadata.version,
      effectiveDate: body.metadata.effective_date,
    };
    return this.ingestService.ingest(meta, requestId, { externalAllowed: body.ai_external_allowed ?? this.options.config.rag.externalDefault });
  }

  async chunks(documentId: number, workspaceId: number, limit: number): Promise<{ document_id: number; chunks: ChunkPreview[] }> {
    return { document_id: documentId, chunks: await this.store.preview(workspaceId, documentId, limit) };
  }

  async deleteDocument(documentId: number, workspaceId: number): Promise<{ document_id: number; deleted_chunks: number }> {
    return { document_id: documentId, deleted_chunks: await this.store.deleteDocument(workspaceId, documentId) };
  }

  async reembedWorkspace(workspaceId: number, externalAllowed?: boolean | null): Promise<Record<string, unknown>> {
    const status = await this.embeddings.status();
    const embedder = await this.embeddings.active(undefined, { externalAllowed: externalAllowed ?? this.options.config.rag.externalDefault });
    const count = await this.store.reembedWorkspace(workspaceId, embedder, this.options.config.embeddings.batchSize);
    return { workspace_id: workspaceId, chunks: count, embedding_model: embedder.modelId, degraded: status.degraded };
  }

  async deleteWorkspace(workspaceId: number): Promise<{ workspace_id: number; purged: WorkspacePurgeCounts }> {
    const purged = await this.privacy.purgeWorkspace(workspaceId);
    this.options.logger.info({ workspaceId, ...purged }, 'workspace purged');
    return { workspace_id: workspaceId, purged };
  }

  async deleteUser(userId: number): Promise<{ user_id: number; purged: { conversation_turns: number } }> {
    const turns = await this.privacy.purgeUser(userId);
    this.options.logger.info({ userId, conversationTurns: turns }, 'user purged');
    return { user_id: userId, purged: { conversation_turns: turns } };
  }

  async pruneMemory(defaultDays: number, workspaces: readonly WorkspaceRetention[]): Promise<{ pruned: number }> {
    const pruned = await this.privacy.pruneMemory(defaultDays, workspaces);
    this.options.logger.info({ rows: pruned }, 'conversation memory pruned');
    return { pruned };
  }

  async providers(locale: string | undefined): Promise<ProviderPayload[]> {
    const loc = normalizeLocale(locale);
    return Promise.all(
      CATALOG.map(async (entry) => {
        const info = await this.keys.info(entry.id);
        const payload: ProviderPayload = {
          id: entry.id,
          label: entry.label,
          configured: await this.keys.isConfigured(entry.id),
          needs_key: entry.needsKey,
          key_source: info.source,
          key_hint: info.hint,
          default_model: entry.defaultModel,
          models: [...entry.models],
        };
        const note = localizedNote(entry, loc);
        if (note) payload.note = note;
        return payload;
      }),
    );
  }

  async models(providerId: string, refresh: boolean): Promise<Record<string, unknown> | null> {
    const entry = getEntry(providerId);
    if (!entry) return null;
    if (refresh) this.liveModels.forget(providerId);
    const catalogModels = entry.models.map((id) => ({ id, free: false, context: null, structured: true }));
    let models: LiveModel[];
    try {
      models = await this.liveModels.models(providerId);
    } catch (error) {
      return { provider: providerId, source: 'catalog', models: catalogModels, error: error instanceof Error ? error.message : String(error) };
    }
    if (models.length === 0) return { provider: providerId, source: 'catalog', models: catalogModels, error: null };
    return { provider: providerId, source: 'live', models, error: null };
  }

  async getSettings(): Promise<SettingsPayload> {
    return {
      ...(await this.settings.get()),
      embeddings_status: await this.embeddings.peek(),
      embeddings_providers: [...EMBEDDING_PROVIDERS],
    };
  }

  async putSettings(patch: AiSettingsPatch): Promise<SettingsPayload> {
    const before = await this.settings.get();
    const after = await this.settings.update(patch);
    const reembedRequired = before.embeddings.provider !== after.embeddings.provider || before.embeddings.model !== after.embeddings.model;
    if (reembedRequired) {
      void this.embeddings.active().catch((error: unknown) => this.options.logger.warn({ error: String(error) }, 'embeddings warm-up failed'));
    }
    return { ...(await this.getSettings()), reembed_required: reembedRequired };
  }

  async putKey(providerId: string, apiKey: string | null): Promise<Record<string, unknown> | 'unknown' | 'keyless'> {
    if (!getEntry(providerId)) return 'unknown';
    if (KEYLESS_PROVIDERS.has(providerId)) return 'keyless';
    const info = await this.keys.setKey(providerId, apiKey);
    this.liveModels.forget(providerId);
    return { provider: providerId, configured: await this.keys.isConfigured(providerId), key_source: info.source, key_hint: info.hint };
  }

  async testProvider(requestId?: string): Promise<Record<string, unknown>> {
    const ai = await this.settings.get();
    const started = performance.now();
    try {
      const managed = await this.manager.chat(
        {
          model: '',
          messages: [
            { role: 'system', content: 'You are a connectivity check. Reply with the single word OK.' },
            { role: 'user', content: 'ping' },
          ],
          maxTokens: 64,
          metadata: { ping: true },
        },
        { purpose: 'test', requestId: requestId ?? null },
      );
      return {
        ok: true,
        latency_ms: managed.latencyMs,
        provider: managed.provider,
        model: managed.model,
        reply: (managed.result.content ?? '').trim().slice(0, 200),
        error: null,
      };
    } catch (error) {
      if (!(error instanceof ProviderError)) throw error;
      return { ok: false, latency_ms: Math.round(performance.now() - started), provider: ai.provider, model: ai.model, error: error.message };
    }
  }

  async usage(days: number): Promise<UsageReport> {
    return usageReport(this.db, days);
  }

  async ready(): Promise<EmbeddingsPeek> {
    return this.embeddings.peek();
  }

  async flush(): Promise<void> {
    await this.chatService.flush();
  }
}
