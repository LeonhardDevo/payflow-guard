import type { RagConfig } from '../config.js';
import { AnthropicProvider } from './anthropic.js';
import { getEntry } from './catalog.js';
import type { ProviderKeys } from './keys.js';
import { MockProvider, type IntentRule, type MockPlanner } from './mock.js';
import { OpenAiCompatibleProvider, openAiSupportsTemperature } from './openaiCompatible.js';
import type { AiProvider } from './types.js';

export interface ProviderBuilder {
  readonly mock: AiProvider;
  build(providerId: string): Promise<AiProvider | null>;
}

export interface ProviderFactoryOptions {
  fetch?: typeof fetch;
  planners?: readonly MockPlanner[];
}

export class ProviderFactory implements ProviderBuilder {
  readonly mock: MockProvider;

  constructor(
    private readonly config: RagConfig,
    private readonly keys: ProviderKeys,
    intentRules: readonly IntentRule[] = [],
    private readonly options: ProviderFactoryOptions = {},
  ) {
    this.mock = new MockProvider(intentRules, options.planners ?? []);
  }

  async build(providerId: string): Promise<AiProvider | null> {
    const entry = getEntry(providerId);
    if (!entry) return null;
    if (entry.kind === 'mock') return this.mock;
    const timeoutMs = this.config.ai.requestTimeoutMs;
    if (entry.kind === 'anthropic') {
      const key = await this.keys.key(providerId);
      if (!key) return null;
      return new AnthropicProvider(key, {
        timeoutMs,
        serverFallbacks: this.config.ai.anthropicServerFallbacks,
        ...(this.config.baseUrls.anthropic ? { baseURL: this.config.baseUrls.anthropic } : {}),
        ...(this.options.fetch ? { fetch: this.options.fetch } : {}),
      });
    }
    if (!entry.baseUrl) return null;
    const key = entry.needsKey ? await this.keys.key(providerId) : 'ollama';
    if (!key) return null;
    return new OpenAiCompatibleProvider(providerId, {
      baseURL: this.config.baseUrls[entry.baseUrl],
      apiKey: key,
      identifyClient: entry.sessionRouted ?? false,
      sendSessionHeader: entry.sessionRouted ?? false,
      maxTokensParam: entry.maxTokensParam ?? 'max_tokens',
      supportsTemperature: providerId === 'openai' ? openAiSupportsTemperature : () => true,
      timeoutMs,
      ...(this.options.fetch ? { fetch: this.options.fetch } : {}),
    });
  }
}
