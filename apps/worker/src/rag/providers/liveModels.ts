import type { RagConfig } from '../config.js';
import { AnthropicProvider } from './anthropic.js';
import { MOCK_MODEL, getEntry } from './catalog.js';
import type { ProviderKeys } from './keys.js';
import { ProviderError } from './types.js';

export const MODELS_CACHE_TTL_MS = 10 * 60_000;
const NON_CHAT_MODEL = /embed|rerank|reward|safety|guard|parse|clip|retriever|whisper|tts|dall-e|moderation/i;

export interface LiveModel {
  id: string;
  free: boolean;
  context: number | null;
  structured: boolean;
}

export type AnthropicLister = (apiKey: string) => Promise<string[]>;

export interface LiveModelsOptions {
  fetch?: typeof fetch;
  anthropicLister?: AnthropicLister;
  ttlMs?: number;
  clock?: () => number;
  timeoutMs?: number;
}

interface ModelListItem {
  id?: unknown;
  pricing?: { prompt?: unknown; completion?: unknown } | null;
  context_length?: unknown;
  supported_parameters?: unknown;
}

export class LiveModels {
  private readonly cache = new Map<string, { at: number; models: LiveModel[] }>();
  private readonly fetchImpl: typeof fetch;
  private readonly anthropicLister: AnthropicLister;
  private readonly ttlMs: number;
  private readonly clock: () => number;
  private readonly timeoutMs: number;

  constructor(
    private readonly config: RagConfig,
    private readonly keys: ProviderKeys,
    options: LiveModelsOptions = {},
  ) {
    this.fetchImpl = options.fetch ?? fetch;
    this.anthropicLister =
      options.anthropicLister ??
      ((apiKey) =>
        new AnthropicProvider(apiKey, {
          timeoutMs: 15_000,
          ...(config.baseUrls.anthropic ? { baseURL: config.baseUrls.anthropic } : {}),
        }).listModels());
    this.ttlMs = options.ttlMs ?? MODELS_CACHE_TTL_MS;
    this.clock = options.clock ?? Date.now;
    this.timeoutMs = options.timeoutMs ?? 15_000;
  }

  forget(providerId: string): void {
    this.cache.delete(providerId);
  }

  async models(providerId: string): Promise<LiveModel[]> {
    const now = this.clock();
    const hit = this.cache.get(providerId);
    if (hit && now - hit.at < this.ttlMs) return hit.models;
    const models = await this.fetchModels(providerId);
    models.sort((a, b) => Number(b.free) - Number(a.free) || Number(b.structured) - Number(a.structured) || a.id.localeCompare(b.id));
    this.cache.set(providerId, { at: now, models });
    return models;
  }

  private async fetchModels(providerId: string): Promise<LiveModel[]> {
    const entry = getEntry(providerId);
    if (!entry) throw new ProviderError(`unknown provider '${providerId}'`, 404);
    if (entry.kind === 'mock') return [{ id: MOCK_MODEL, free: true, context: null, structured: true }];
    if (entry.kind === 'anthropic') {
      const key = await this.keys.key(providerId);
      if (!key) return [];
      return (await this.anthropicLister(key)).map((id) => ({ id, free: false, context: null, structured: true }));
    }
    if (!entry.baseUrl) return [];
    const key = entry.needsKey ? await this.keys.key(providerId) : null;
    if (entry.needsKey && !key && !entry.publicModelList) return [];
    let response: Response;
    try {
      response = await this.fetchImpl(`${this.config.baseUrls[entry.baseUrl]}/models`, {
        headers: key ? { authorization: `Bearer ${key}` } : {},
        signal: AbortSignal.timeout(this.timeoutMs),
      });
    } catch (error) {
      throw new ProviderError(`${providerId} models: ${error instanceof Error ? error.message : String(error)}`, null);
    }
    if (!response.ok) throw new ProviderError(`${providerId} models HTTP ${response.status}`, response.status);
    const body = (await response.json().catch(() => ({}))) as { data?: ModelListItem[] };
    const models: LiveModel[] = [];
    for (const item of body.data ?? []) {
      const id = typeof item.id === 'string' ? item.id : '';
      if (id === '' || NON_CHAT_MODEL.test(id)) continue;
      const params = Array.isArray(item.supported_parameters) ? (item.supported_parameters as unknown[]) : null;
      models.push({
        id,
        free:
          (entry.freeTrial ?? false) ||
          id.endsWith(':free') ||
          (String(item.pricing?.prompt) === '0' && String(item.pricing?.completion) === '0'),
        context: typeof item.context_length === 'number' ? item.context_length : null,
        structured: params === null || params.includes('structured_outputs'),
      });
    }
    return models;
  }
}
