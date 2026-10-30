import type { Logger } from '../../logger.js';
import type { RagConfig } from '../config.js';
import type { ProviderKeys } from '../providers/keys.js';
import type { AiSettingsService, EmbeddingsSettings } from '../settings/store.js';
import { HashEmbedder } from './hash.js';
import { LocalEmbedder, loadTransformersExtractor, type ExtractorLoader } from './local.js';
import { RemoteEmbedder } from './remote.js';
import { EmbeddingError, type Embedder } from './types.js';

const FAILURE_RETRY_MS = 600_000;
const ON_DEVICE_PROVIDERS: ReadonlySet<string> = new Set(['hash', 'local']);

export interface EmbeddingsStatus {
  configured: string;
  active: string;
  dims: number;
  degraded: boolean;
  reason: string | null;
}

export interface EmbeddingsPeek {
  configured: string;
  model: string | null;
  state: 'ready' | 'loading' | 'degraded' | 'not_loaded';
  reason?: string;
}

export interface EmbeddingsRegistryOptions {
  fetch?: typeof fetch;
  localLoader?: ExtractorLoader;
  clock?: () => number;
}

export class EmbeddingsRegistry {
  private readonly cache = new Map<string, Embedder>();
  private readonly failures = new Map<string, { at: number; reason: string }>();
  private readonly building = new Map<string, Promise<{ embedder: Embedder; reason: string | null }>>();
  private readonly clock: () => number;

  constructor(
    private readonly config: RagConfig,
    private readonly settings: AiSettingsService,
    private readonly keys: ProviderKeys,
    private readonly logger: Logger,
    private readonly options: EmbeddingsRegistryOptions = {},
  ) {
    this.clock = options.clock ?? Date.now;
  }

  async currentConfig(): Promise<EmbeddingsSettings> {
    return (await this.settings.get()).embeddings;
  }

  async isExternal(): Promise<boolean> {
    return !ON_DEVICE_PROVIDERS.has((await this.currentConfig()).provider);
  }

  async active(waitMs?: number, options: { externalAllowed?: boolean } = {}): Promise<Embedder> {
    const config = await this.currentConfig();
    if (options.externalAllowed === false && !ON_DEVICE_PROVIDERS.has(config.provider)) return new HashEmbedder();
    return (await this.resolve(config, waitMs)).embedder;
  }

  async status(): Promise<EmbeddingsStatus> {
    const config = await this.currentConfig();
    const { embedder, reason } = await this.resolve(config);
    return {
      configured: `${config.provider}:${config.model}`,
      active: embedder.modelId,
      dims: embedder.dims,
      degraded: reason !== null,
      reason,
    };
  }

  async peek(): Promise<EmbeddingsPeek> {
    const config = await this.currentConfig();
    const key = `${config.provider}:${config.model}`;
    const cached = this.cache.get(key);
    if (cached) return { configured: key, model: cached.modelId, state: 'ready' };
    if (this.building.has(key)) return { configured: key, model: null, state: 'loading' };
    const failure = this.failures.get(key);
    if (failure) return { configured: key, model: new HashEmbedder().modelId, state: 'degraded', reason: failure.reason };
    return { configured: key, model: null, state: 'not_loaded' };
  }

  private cached(key: string): { embedder: Embedder; reason: string | null } | null {
    const embedder = this.cache.get(key);
    if (embedder) return { embedder, reason: null };
    const failure = this.failures.get(key);
    if (failure && this.clock() - failure.at < FAILURE_RETRY_MS) return { embedder: new HashEmbedder(), reason: failure.reason };
    return null;
  }

  private async resolve(config: EmbeddingsSettings, waitMs?: number): Promise<{ embedder: Embedder; reason: string | null }> {
    const key = `${config.provider}:${config.model}`;
    const hit = this.cached(key);
    if (hit) return hit;
    let build = this.building.get(key);
    if (!build) {
      build = this.buildAndCache(config, key).finally(() => this.building.delete(key));
      this.building.set(key, build);
    }
    if (waitMs === undefined) return build;
    let timer: NodeJS.Timeout | undefined;
    const timeout = new Promise<null>((resolve) => {
      timer = setTimeout(() => resolve(null), Math.max(0, waitMs));
    });
    const winner = await Promise.race([build, timeout]);
    clearTimeout(timer);
    if (winner) return winner;
    this.logger.warn({ configured: key }, 'embedding model is still loading; using hash embeddings for this request');
    return { embedder: new HashEmbedder(), reason: 'embedding model is still loading' };
  }

  private async buildAndCache(config: EmbeddingsSettings, key: string): Promise<{ embedder: Embedder; reason: string | null }> {
    try {
      const embedder = await this.build(config);
      this.cache.set(key, embedder);
      this.failures.delete(key);
      return { embedder, reason: null };
    } catch (error) {
      const reason = error instanceof Error ? error.message : String(error);
      this.logger.warn({ configured: key, reason }, 'embeddings provider unavailable; falling back to hash embeddings');
      this.failures.set(key, { at: this.clock(), reason });
      return { embedder: new HashEmbedder(), reason };
    }
  }

  private async build(config: EmbeddingsSettings): Promise<Embedder> {
    if (config.provider === 'hash') {
      const match = /^hash-(\d+)$/.exec(config.model);
      return new HashEmbedder(match ? Number(match[1]) : 384);
    }
    if (config.provider === 'local') {
      const local = new LocalEmbedder(
        config.model,
        this.config.embeddings.cacheDir,
        this.config.embeddings.batchSize,
        this.options.localLoader ?? loadTransformersExtractor,
      );
      await local.load();
      return local;
    }
    const baseUrl = this.config.embeddings.baseUrl ?? this.config.baseUrls.openai;
    const apiKey = this.config.embeddings.apiKey ?? (await this.keys.key('openai'));
    if (!baseUrl) throw new EmbeddingError('EMBEDDINGS_BASE_URL is not set');
    return new RemoteEmbedder({
      baseUrl,
      model: config.model,
      apiKey,
      dims: this.config.embeddings.dims,
      batchSize: this.config.embeddings.batchSize,
      ...(this.options.fetch ? { fetch: this.options.fetch } : {}),
    });
  }
}
