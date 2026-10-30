import { EMBEDDING_PROVIDERS, type EmbeddingsProviderName, type RagConfig } from '../config.js';
import type { RagDb } from '../db/ragDb.js';
import { getEntry } from '../providers/catalog.js';

export const AI_SETTINGS_KEY = 'ai';
export const PROVIDER_KEYS_SETTING = 'provider_keys';

export const DEFAULT_EMBEDDING_MODELS: Readonly<Record<EmbeddingsProviderName, string>> = {
  local: 'Xenova/paraphrase-multilingual-MiniLM-L12-v2',
  'openai-compatible': 'text-embedding-3-small',
  hash: 'hash-384',
};

export interface EmbeddingsSettings {
  provider: EmbeddingsProviderName;
  model: string;
}

export interface AiSettings {
  provider: string;
  model: string;
  fallback_model: string | null;
  temperature: number;
  embeddings: EmbeddingsSettings;
}

export interface AiSettingsPatch {
  provider?: string | null;
  model?: string | null;
  fallback_model?: string | null;
  temperature?: number | null;
  embeddings?: { provider?: string | null; model?: string | null } | null;
}

export class SettingsValidationError extends Error {
  constructor(
    readonly field: string,
    message: string,
  ) {
    super(message);
    this.name = 'SettingsValidationError';
  }
}

export interface RawSettingsStore {
  getRaw<T>(key: string, fallback: T): Promise<T>;
  setRaw(key: string, value: unknown): Promise<void>;
}

export class PgSettingsStore implements RawSettingsStore {
  private readonly cache = new Map<string, { at: number; value: unknown }>();

  constructor(
    private readonly db: RagDb,
    private readonly ttlMs = 5000,
  ) {}

  async getRaw<T>(key: string, fallback: T): Promise<T> {
    const hit = this.cache.get(key);
    if (hit && Date.now() - hit.at < this.ttlMs) return (hit.value ?? fallback) as T;
    const { rows } = await this.db.query<{ value: unknown }>(`SELECT value FROM ${this.db.table('settings')} WHERE key = $1`, [key]);
    const value = rows[0]?.value ?? null;
    this.cache.set(key, { at: Date.now(), value });
    return (value ?? fallback) as T;
  }

  async setRaw(key: string, value: unknown): Promise<void> {
    await this.db.query(
      `INSERT INTO ${this.db.table('settings')} (key, value, updated_at) VALUES ($1, $2::jsonb, now())
       ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()`,
      [key, JSON.stringify(value)],
    );
    this.cache.delete(key);
  }

  invalidate(): void {
    this.cache.clear();
  }
}

export class MemorySettingsStore implements RawSettingsStore {
  readonly values = new Map<string, unknown>();

  async getRaw<T>(key: string, fallback: T): Promise<T> {
    return (this.values.get(key) ?? fallback) as T;
  }

  async setRaw(key: string, value: unknown): Promise<void> {
    this.values.set(key, structuredClone(value));
  }
}

function isEmbeddingsProvider(value: unknown): value is EmbeddingsProviderName {
  return typeof value === 'string' && (EMBEDDING_PROVIDERS as readonly string[]).includes(value);
}

function text(value: unknown): string {
  return typeof value === 'string' ? value.trim() : '';
}

export class AiSettingsService {
  constructor(
    private readonly store: RawSettingsStore,
    private readonly config: RagConfig,
  ) {}

  get raw(): RawSettingsStore {
    return this.store;
  }

  async get(): Promise<AiSettings> {
    const stored = await this.store.getRaw<Record<string, unknown>>(AI_SETTINGS_KEY, {});
    const env = this.config;
    const requested = text(stored.provider) || env.ai.provider || 'mock';
    const entry = getEntry(requested) ?? getEntry('mock')!;
    const provider = entry.id;
    const model = text(stored.model) || (provider === env.ai.provider ? (env.ai.model ?? '') : '') || entry.defaultModel;
    const fallbackRaw = 'fallback_model' in stored ? stored.fallback_model : provider === env.ai.provider ? env.ai.fallbackModel : null;
    const fallback = text(fallbackRaw) || null;
    const temperatureRaw = typeof stored.temperature === 'number' ? stored.temperature : env.ai.temperature;
    const embeddingsStored = (stored.embeddings ?? {}) as Record<string, unknown>;
    const embeddingsProvider = isEmbeddingsProvider(embeddingsStored.provider) ? embeddingsStored.provider : env.embeddings.provider;
    const embeddingsModel =
      text(embeddingsStored.model) ||
      (embeddingsProvider === env.embeddings.provider ? (env.embeddings.model ?? '') : '') ||
      DEFAULT_EMBEDDING_MODELS[embeddingsProvider];
    return {
      provider,
      model,
      fallback_model: fallback,
      temperature: temperatureRaw,
      embeddings: { provider: embeddingsProvider, model: embeddingsModel },
    };
  }

  async update(patch: AiSettingsPatch): Promise<AiSettings> {
    const current = await this.get();
    const merged: Record<string, unknown> = { ...current, embeddings: { ...current.embeddings } };
    const providerChanged = patch.provider !== undefined && patch.provider !== null && patch.provider !== current.provider;
    for (const key of ['provider', 'model', 'fallback_model', 'temperature'] as const) {
      if (patch[key] !== undefined) merged[key] = patch[key];
    }
    if (providerChanged && (patch.model === undefined || patch.model === null || patch.model === '')) {
      merged.model = getEntry(String(merged.provider))?.defaultModel ?? '';
    }
    if (patch.embeddings) {
      const embeddings = { ...current.embeddings } as Record<string, unknown>;
      const next = patch.embeddings;
      if (next.provider && next.provider !== current.embeddings.provider && !next.model) {
        embeddings.model = isEmbeddingsProvider(next.provider) ? DEFAULT_EMBEDDING_MODELS[next.provider] : '';
      }
      if (next.provider) embeddings.provider = next.provider;
      if (next.model) embeddings.model = next.model;
      merged.embeddings = embeddings;
    }
    const validated = validateSettings(merged);
    await this.store.setRaw(AI_SETTINGS_KEY, validated);
    return validated;
  }
}

export function validateSettings(data: Record<string, unknown>): AiSettings {
  const provider = text(data.provider);
  if (!getEntry(provider)) throw new SettingsValidationError('provider', `unknown provider '${provider}'`);
  const model = text(data.model);
  if (model === '' || model.length > 200) throw new SettingsValidationError('model', 'model is required (max 200 characters)');
  const fallback = text(data.fallback_model);
  if (fallback.length > 200) throw new SettingsValidationError('fallback_model', 'fallback model is too long');
  const temperature = data.temperature === null || data.temperature === undefined ? 0.2 : Number(data.temperature);
  if (!Number.isFinite(temperature)) throw new SettingsValidationError('temperature', 'temperature must be a number');
  if (temperature < 0 || temperature > 2) throw new SettingsValidationError('temperature', 'temperature must be between 0 and 2');
  const embeddings = (data.embeddings ?? {}) as Record<string, unknown>;
  if (!isEmbeddingsProvider(embeddings.provider)) {
    throw new SettingsValidationError('embeddings.provider', `unknown embeddings provider '${String(embeddings.provider)}'`);
  }
  const embeddingsModel = text(embeddings.model) || DEFAULT_EMBEDDING_MODELS[embeddings.provider];
  if (embeddingsModel.length > 200) throw new SettingsValidationError('embeddings.model', 'embeddings model is too long');
  return {
    provider,
    model,
    fallback_model: fallback || null,
    temperature: Math.round(temperature * 1000) / 1000,
    embeddings: { provider: embeddings.provider, model: embeddingsModel },
  };
}
