import { setTimeout as sleepMs } from 'node:timers/promises';
import type { Logger } from '../../logger.js';
import type { RagDb } from '../db/ragDb.js';
import type { RagMetrics } from '../metrics.js';
import { redactPii } from '../privacy/redact.js';
import type { AiSettings, AiSettingsService } from '../settings/store.js';
import { getEntry, isLocalProvider } from './catalog.js';
import type { ProviderBuilder } from './factory.js';
import { estimateCostUsd } from './pricing.js';
import {
  EMPTY_USAGE,
  ExternalProviderBlockedError,
  ProviderError,
  ProviderNotConfiguredError,
  type AiProvider,
  type ChatMessage,
  type ChatRequest,
  type ChatResult,
  type ChatUsage,
} from './types.js';

export const MAX_ATTEMPTS = 6;
export const FALLBACK_AFTER_ATTEMPTS = 3;
const TRANSIENT_STATUSES = new Set([500, 502, 503, 504, 529]);

export interface UsageRecord {
  provider: string;
  model: string;
  purpose: string;
  usage: ChatUsage;
  costUsd: number;
  latencyMs: number;
  workspaceId: number | null;
  ok: boolean;
  error: string | null;
  requestId: string | null;
}

export interface UsageRecorder {
  record(record: UsageRecord): Promise<void>;
}

export class PgUsageRecorder implements UsageRecorder {
  constructor(private readonly db: RagDb) {}

  async record(record: UsageRecord): Promise<void> {
    await this.db.query(
      `INSERT INTO ${this.db.table('ai_usage')}
         (workspace_id, provider, model, purpose, prompt_tokens, completion_tokens, cached_tokens, cost_usd, latency_ms, ok, error, request_id)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12)`,
      [
        record.workspaceId,
        record.provider.slice(0, 64),
        record.model.slice(0, 200),
        record.purpose.slice(0, 64),
        record.usage.promptTokens,
        record.usage.completionTokens,
        record.usage.cachedTokens,
        record.costUsd,
        record.latencyMs,
        record.ok,
        record.error ? record.error.slice(0, 2000) : null,
        record.requestId ? record.requestId.slice(0, 64) : null,
      ],
    );
  }
}

export interface ManagedChatResult {
  result: ChatResult;
  provider: string;
  model: string;
  costUsd: number;
  latencyMs: number;
  attempts: number;
}

export interface ManagedChatOptions {
  purpose: string;
  workspaceId?: number | null;
  provider?: AiProvider;
  requestId?: string | null;
  allowExternal?: boolean;
}

export interface AiManagerOptions {
  retryBaseMs: number;
  retryMaxMs: number;
  maxTokens: number;
  sleep?: (ms: number) => Promise<void>;
  logger: Logger;
  metrics?: RagMetrics;
}

export function redactMessages(messages: readonly ChatMessage[]): ChatMessage[] {
  return messages.map((message) =>
    message.role === 'system'
      ? message
      : {
          ...message,
          content: redactPii(message.content),
          ...(message.toolCalls ? { toolCalls: message.toolCalls.map((call) => ({ ...call, arguments: redactPii(call.arguments) })) } : {}),
        },
  );
}

export class AiManager {
  private readonly sleep: (ms: number) => Promise<void>;

  constructor(
    private readonly settings: AiSettingsService,
    readonly factory: ProviderBuilder,
    private readonly recorder: UsageRecorder,
    private readonly options: AiManagerOptions,
  ) {
    this.sleep = options.sleep ?? ((ms) => sleepMs(ms).then(() => undefined));
  }

  async currentSettings(): Promise<AiSettings> {
    return this.settings.get();
  }

  async active(): Promise<{ provider: AiProvider; settings: AiSettings }> {
    const settings = await this.settings.get();
    const provider = await this.factory.build(settings.provider);
    if (!provider) throw new ProviderNotConfiguredError(settings.provider);
    return { provider, settings };
  }

  async chat(request: ChatRequest, options: ManagedChatOptions): Promise<ManagedChatResult> {
    const started = performance.now();
    const workspaceId = options.workspaceId ?? null;
    let settings = await this.settings.get();
    let provider = options.provider;
    if (!provider) {
      if (options.allowExternal === false && !isLocalProvider(settings.provider)) {
        const error = new ExternalProviderBlockedError(settings.provider);
        await this.recordFailure(settings.provider, request.model || settings.model, options, started, error);
        throw error;
      }
      const built = await this.factory.build(settings.provider);
      if (!built) {
        const error = new ProviderNotConfiguredError(settings.provider);
        await this.recordFailure(settings.provider, request.model || settings.model, options, started, error);
        throw error;
      }
      provider = built;
    } else if (provider.id !== settings.provider) {
      const entry = getEntry(provider.id);
      settings = { ...settings, provider: provider.id, model: entry?.defaultModel ?? settings.model, fallback_model: null };
    }

    let usedModel = request.model || settings.model;
    const fallback = settings.fallback_model?.trim() || null;
    const temperature = request.temperature ?? settings.temperature;
    const maxTokens = request.maxTokens ?? this.options.maxTokens;
    const sessionId = request.sessionId ?? (workspaceId ? `ws-${workspaceId}-${options.purpose}` : `rag-${options.purpose}`);
    const messages = isLocalProvider(provider.id) ? request.messages : redactMessages(request.messages);
    let attempt = 0;
    let attemptsOnModel = 0;
    let result: ChatResult;

    for (;;) {
      attempt += 1;
      attemptsOnModel += 1;
      try {
        result = await provider.chat({ ...request, messages, model: usedModel, temperature, maxTokens, sessionId });
        break;
      } catch (caught) {
        const error = caught instanceof ProviderError ? caught : new ProviderError(caught instanceof Error ? caught.message : String(caught));
        const status = error.status;
        if ((status === 403 || status === 404) && fallback && fallback !== usedModel) {
          this.options.logger.warn({ provider: provider.id, from: usedModel, to: fallback, status }, 'model unavailable; switching to fallback model');
          usedModel = fallback;
          attemptsOnModel = 0;
          continue;
        }
        const retryable = status === 429 || status === null || TRANSIENT_STATUSES.has(status);
        if (!retryable || error instanceof ProviderNotConfiguredError) {
          await this.recordFailure(provider.id, usedModel, options, started, error);
          throw error;
        }
        if (attempt >= FALLBACK_AFTER_ATTEMPTS && fallback && fallback !== usedModel) {
          this.options.logger.warn({ provider: provider.id, from: usedModel, to: fallback, status }, 'rate limited; switching to fallback model');
          usedModel = fallback;
          attemptsOnModel = 0;
          continue;
        }
        const transientExhausted = status !== 429 && attemptsOnModel >= FALLBACK_AFTER_ATTEMPTS;
        if (attempt >= MAX_ATTEMPTS || transientExhausted) {
          await this.recordFailure(provider.id, usedModel, options, started, error);
          throw error;
        }
        const delay = this.backoff(attemptsOnModel, error.retryAfterMs);
        this.options.logger.info({ provider: provider.id, model: usedModel, status, attempt, delayMs: delay }, 'llm call retry');
        await this.sleep(delay);
      }
    }

    const latencyMs = Math.round(performance.now() - started);
    const costUsd = estimateCostUsd(usedModel, result.usage);
    await this.safeRecord({
      provider: provider.id,
      model: usedModel,
      purpose: options.purpose,
      usage: result.usage,
      costUsd,
      latencyMs,
      workspaceId,
      ok: true,
      error: null,
      requestId: options.requestId ?? null,
    });
    this.options.metrics?.llmCalls.inc({ provider: provider.id, purpose: options.purpose, status: 'ok' });
    this.options.metrics?.llmTokens.inc({ provider: provider.id, kind: 'prompt' }, result.usage.promptTokens);
    this.options.metrics?.llmTokens.inc({ provider: provider.id, kind: 'completion' }, result.usage.completionTokens);
    return { result, provider: provider.id, model: usedModel, costUsd, latencyMs, attempts: attempt };
  }

  private backoff(attempt: number, retryAfterMs: number | null): number {
    if (retryAfterMs !== null && retryAfterMs >= 0) return Math.min(this.options.retryMaxMs, retryAfterMs);
    return Math.min(this.options.retryMaxMs, this.options.retryBaseMs * 2 ** (attempt - 1));
  }

  private async recordFailure(providerId: string, model: string, options: ManagedChatOptions, started: number, error: Error): Promise<void> {
    this.options.metrics?.llmCalls.inc({ provider: providerId, purpose: options.purpose, status: 'error' });
    await this.safeRecord({
      provider: providerId,
      model,
      purpose: options.purpose,
      usage: EMPTY_USAGE,
      costUsd: 0,
      latencyMs: Math.round(performance.now() - started),
      workspaceId: options.workspaceId ?? null,
      ok: false,
      error: error.message,
      requestId: options.requestId ?? null,
    });
  }

  private async safeRecord(record: UsageRecord): Promise<void> {
    try {
      await this.recorder.record(record);
    } catch (error) {
      this.options.logger.warn({ error: error instanceof Error ? error.message : String(error) }, 'ai usage could not be recorded');
    }
  }
}
