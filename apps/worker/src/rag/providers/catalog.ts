import type { RagConfig } from '../config.js';

export type ProviderKind = 'mock' | 'anthropic' | 'openai-compatible';
export type BaseUrlKey = Exclude<keyof RagConfig['baseUrls'], 'anthropic'>;

export interface CatalogEntry {
  id: string;
  label: string;
  kind: ProviderKind;
  defaultModel: string;
  models: readonly string[];
  needsKey: boolean;
  local: boolean;
  note?: { en: string; ru: string };
  baseUrl?: BaseUrlKey;
  publicModelList?: boolean;
  freeTrial?: boolean;
  sessionRouted?: boolean;
  maxTokensParam?: 'max_tokens' | 'max_completion_tokens';
}

export const MOCK_MODEL = 'mock-extractive-1';

export const CATALOG: readonly CatalogEntry[] = [
  {
    id: 'mock',
    label: 'Mock (offline, deterministic)',
    kind: 'mock',
    defaultModel: MOCK_MODEL,
    models: [MOCK_MODEL],
    needsKey: false,
    local: true,
    note: {
      en: 'Built-in deterministic provider: extractive answers from retrieved documents and rule-based tool routing. No key and no network required.',
      ru: 'Встроенный детерминированный провайдер: ответы цитатами из найденных документов и маршрутизация инструментов по правилам. Ключ и сеть не нужны.',
    },
  },
  {
    id: 'anthropic',
    label: 'Anthropic (Claude)',
    kind: 'anthropic',
    defaultModel: 'claude-opus-5-5',
    models: ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-fable-5-1'],
    needsKey: true,
    local: false,
  },
  {
    id: 'openai',
    label: 'OpenAI',
    kind: 'openai-compatible',
    defaultModel: 'gpt-5-mini',
    models: ['gpt-5', 'gpt-5-mini', 'gpt-5-nano'],
    needsKey: true,
    local: false,
    baseUrl: 'openai',
    maxTokensParam: 'max_completion_tokens',
  },
  {
    id: 'deepseek',
    label: 'DeepSeek (official)',
    kind: 'openai-compatible',
    defaultModel: 'deepseek-chat',
    models: ['deepseek-chat', 'deepseek-reasoner'],
    needsKey: true,
    local: false,
    baseUrl: 'deepseek',
  },
  {
    id: 'openrouter',
    label: 'OpenRouter',
    kind: 'openai-compatible',
    defaultModel: 'z-ai/glm-5.2:free',
    models: ['z-ai/glm-5.2:free', 'minimax/minimax-m3:free', 'deepseek/deepseek-v4-flash', 'anthropic/claude-sonnet-5'],
    needsKey: true,
    local: false,
    baseUrl: 'openrouter',
    publicModelList: true,
  },
  {
    id: 'nvidia',
    label: 'NVIDIA (build.nvidia.com)',
    kind: 'openai-compatible',
    defaultModel: 'nvidia/nemotron-3-ultra-550b-a55b',
    models: ['nvidia/nemotron-3-ultra-550b-a55b', 'nvidia/nemotron-3.5-lightning-30b-a3b', 'deepseek-ai/deepseek-v4.1-flash'],
    needsKey: true,
    local: false,
    baseUrl: 'nvidia',
    publicModelList: true,
    freeTrial: true,
    note: {
      en: 'NVIDIA free trial: about 40 requests per minute, meant for prototypes and tests. Get a key (nvapi-…) at build.nvidia.com → Get API Key.',
      ru: 'Бесплатный пробный доступ NVIDIA: около 40 запросов в минуту, для прототипов и тестов. Ключ (nvapi-…) — на build.nvidia.com → Get API Key.',
    },
  },
  {
    id: 'opencode-zen',
    label: 'OpenCode Zen',
    kind: 'openai-compatible',
    defaultModel: 'deepseek/deepseek-v4-flash-free',
    models: ['deepseek/deepseek-v4-flash-free'],
    needsKey: true,
    local: false,
    baseUrl: 'opencode-zen',
    sessionRouted: true,
    note: {
      en: 'The free OpenCode tier works only inside their app; only paid Zen models are reachable from here.',
      ru: 'Бесплатный тариф OpenCode работает только внутри их приложения — отсюда доступны только платные модели Zen.',
    },
  },
  {
    id: 'opencode-go',
    label: 'OpenCode Go (subscription)',
    kind: 'openai-compatible',
    defaultModel: 'deepseek-v4-flash',
    models: ['deepseek-v4-flash', 'qwen3.8-max', 'glm-5.3-flash', 'grok-4.6'],
    needsKey: true,
    local: false,
    baseUrl: 'opencode-go',
    sessionRouted: true,
  },
  {
    id: 'ollama',
    label: 'Ollama (local)',
    kind: 'openai-compatible',
    defaultModel: 'qwen3',
    models: [],
    needsKey: false,
    local: true,
    baseUrl: 'ollama',
  },
];

const BY_ID = new Map(CATALOG.map((entry) => [entry.id, entry]));

export const KEYLESS_PROVIDERS: ReadonlySet<string> = new Set(CATALOG.filter((entry) => !entry.needsKey).map((entry) => entry.id));

export function getEntry(providerId: string): CatalogEntry | undefined {
  return BY_ID.get(providerId);
}

export function isLocalProvider(providerId: string): boolean {
  return getEntry(providerId)?.local ?? false;
}

export function localizedNote(entry: CatalogEntry, locale: 'ru' | 'en'): string | undefined {
  return entry.note?.[locale] ?? entry.note?.en;
}
