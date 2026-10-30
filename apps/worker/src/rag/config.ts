import { homedir } from 'node:os';
import { join } from 'node:path';
import { z } from 'zod';

export const EMBEDDING_PROVIDERS = ['local', 'openai-compatible', 'hash'] as const;
export type EmbeddingsProviderName = (typeof EMBEDDING_PROVIDERS)[number];

const booleanish = z
  .union([z.boolean(), z.string()])
  .transform((value) => (typeof value === 'boolean' ? value : ['1', 'true', 'yes', 'on'].includes(value.trim().toLowerCase())));

const optionalString = z
  .string()
  .optional()
  .transform((value) => (value === undefined || value.trim() === '' ? undefined : value.trim()));

const baseUrl = (fallback: string) =>
  z
    .string()
    .trim()
    .url()
    .default(fallback)
    .transform((value) => value.replace(/\/+$/, ''));

const ragEnvSchema = z.object({
  RAG_SCHEMA: z
    .string()
    .trim()
    .regex(/^[a-z_][a-z0-9_]{0,40}$/, 'RAG_SCHEMA must be a lowercase identifier')
    .default('rag'),
  AI_SETTINGS_KEY: optionalString,
  AI_PROVIDER: z.string().trim().default('mock'),
  AI_MODEL: optionalString,
  AI_FALLBACK_MODEL: optionalString,
  AI_TEMPERATURE: z.coerce.number().min(0).max(2).default(0.2),
  AI_MAX_TOKENS: z.coerce.number().int().min(16).max(128_000).default(4096),
  AI_RETRY_BASE_MS: z.coerce.number().int().min(0).default(2000),
  AI_RETRY_MAX_MS: z.coerce.number().int().min(0).default(30_000),
  AI_REQUEST_TIMEOUT_MS: z.coerce.number().int().positive().default(90_000),
  ANTHROPIC_SERVER_FALLBACKS: booleanish.default(true),

  ANTHROPIC_API_KEY: optionalString,
  ANTHROPIC_BASE_URL: optionalString,
  OPENAI_API_KEY: optionalString,
  OPENAI_BASE_URL: baseUrl('https://api.openai.com/v1'),
  DEEPSEEK_API_KEY: optionalString,
  DEEPSEEK_BASE_URL: baseUrl('https://api.deepseek.com'),
  OPENROUTER_API_KEY: optionalString,
  OPENROUTER_BASE_URL: baseUrl('https://openrouter.ai/api/v1'),
  NVIDIA_API_KEY: optionalString,
  NVIDIA_BASE_URL: baseUrl('https://integrate.api.nvidia.com/v1'),
  OPENCODE_ZEN_API_KEY: optionalString,
  OPENCODE_ZEN_BASE_URL: baseUrl('https://opencode.ai/zen/v1'),
  OPENCODE_GO_API_KEY: optionalString,
  OPENCODE_GO_BASE_URL: baseUrl('https://opencode.ai/zen/go/v1'),
  OLLAMA_BASE_URL: baseUrl('http://localhost:11434/v1'),
  LOCAL_LLM_PROVIDER: z.enum(['mock', 'ollama']).default('mock'),

  EMBEDDINGS_PROVIDER: z.enum(EMBEDDING_PROVIDERS).default('hash'),
  EMBEDDINGS_MODEL: optionalString,
  EMBEDDINGS_BASE_URL: optionalString,
  EMBEDDINGS_API_KEY: optionalString,
  EMBEDDINGS_DIMS: z.coerce.number().int().min(8).max(8192).default(384),
  EMBEDDINGS_BATCH_SIZE: z.coerce.number().int().min(1).max(512).default(32),
  EMBEDDINGS_CACHE_DIR: optionalString,
  EMBEDDINGS_LOAD_WAIT_MS: z.coerce.number().int().min(0).default(15_000),

  RAG_MIN_CONFIDENCE: z.coerce.number().min(0).max(1).default(0.35),
  RAG_TOP_K: z.coerce.number().int().min(1).max(50).default(6),
  RAG_CANDIDATES: z.coerce.number().int().min(1).max(1000).default(40),
  RAG_RRF_K: z.coerce.number().int().min(1).max(1000).default(60),
  RAG_CHUNK_TOKENS: z.coerce.number().int().min(50).max(8000).default(700),
  RAG_CHUNK_OVERLAP: z.coerce.number().min(0).lt(0.5).default(0.15),
  RAG_MEMORY_TURNS: z.coerce.number().int().min(0).max(50).default(6),
  RAG_PRIVILEGED_ROLES: z.string().default(''),
  RAG_EXTERNAL_DEFAULT: booleanish.default(false),
  RAG_MAX_FILE_MB: z.coerce.number().int().min(1).max(1024).default(50),
  AGENT_MAX_ITERATIONS: z.coerce.number().int().min(0).max(10).default(4),

  OCR_LANGUAGES: z.string().trim().default('rus+eng'),
  TESSERACT_CMD: z.string().trim().default('tesseract'),
  TESSDATA_PREFIX: optionalString,
  OCR_TIMEOUT_MS: z.coerce.number().int().positive().default(120_000),
  OCR_MAX_PAGES: z.coerce.number().int().min(1).max(5000).default(300),

  RAG_PARSE_ISOLATION: booleanish.default(true),
  RAG_PARSE_TIMEOUT_MS: z.coerce.number().int().min(1000).default(300_000),
  RAG_PARSE_MAX_MEMORY_MB: z.coerce.number().int().min(64).max(8192).default(256),
});

export interface RagConfig {
  schema: string;
  settingsKey: string | undefined;
  ai: {
    provider: string;
    model: string | undefined;
    fallbackModel: string | undefined;
    temperature: number;
    maxTokens: number;
    retryBaseMs: number;
    retryMaxMs: number;
    requestTimeoutMs: number;
    anthropicServerFallbacks: boolean;
    localLlmProvider: 'mock' | 'ollama';
  };
  providerKeys: Record<string, string | undefined>;
  baseUrls: {
    anthropic: string | undefined;
    openai: string;
    deepseek: string;
    openrouter: string;
    nvidia: string;
    'opencode-zen': string;
    'opencode-go': string;
    ollama: string;
  };
  embeddings: {
    provider: EmbeddingsProviderName;
    model: string | undefined;
    baseUrl: string | undefined;
    apiKey: string | undefined;
    dims: number;
    batchSize: number;
    cacheDir: string;
    loadWaitMs: number;
  };
  rag: {
    minConfidence: number;
    topK: number;
    candidates: number;
    rrfK: number;
    chunkTokens: number;
    chunkOverlap: number;
    memoryTurns: number;
    privilegedRoles: string[];
    externalDefault: boolean;
    maxFileBytes: number;
    maxIterations: number;
  };
  ocr: {
    languages: string;
    command: string;
    tessdataPrefix: string | undefined;
    timeoutMs: number;
    maxPages: number;
  };
  parse: {
    isolated: boolean;
    timeoutMs: number;
    maxOldSpaceMb: number;
  };
}

export type RagConfigResult = { ok: true; config: RagConfig } | { ok: false; issues: string[] };

export function parseRagConfig(source: Record<string, string | undefined>, isProduction: boolean): RagConfigResult {
  const parsed = ragEnvSchema.safeParse(source);
  if (!parsed.success) {
    return { ok: false, issues: parsed.error.issues.map((issue) => `${issue.path.join('.') || '(root)'}: ${issue.message}`) };
  }
  const env = parsed.data;
  if (isProduction && (env.AI_SETTINGS_KEY ?? '').length < 32) {
    return { ok: false, issues: ['AI_SETTINGS_KEY: is required (32+ characters) in production'] };
  }
  return {
    ok: true,
    config: {
      schema: env.RAG_SCHEMA,
      settingsKey: env.AI_SETTINGS_KEY,
      ai: {
        provider: env.AI_PROVIDER,
        model: env.AI_MODEL,
        fallbackModel: env.AI_FALLBACK_MODEL,
        temperature: env.AI_TEMPERATURE,
        maxTokens: env.AI_MAX_TOKENS,
        retryBaseMs: env.AI_RETRY_BASE_MS,
        retryMaxMs: env.AI_RETRY_MAX_MS,
        requestTimeoutMs: env.AI_REQUEST_TIMEOUT_MS,
        anthropicServerFallbacks: env.ANTHROPIC_SERVER_FALLBACKS,
        localLlmProvider: env.LOCAL_LLM_PROVIDER,
      },
      providerKeys: {
        anthropic: env.ANTHROPIC_API_KEY,
        openai: env.OPENAI_API_KEY,
        deepseek: env.DEEPSEEK_API_KEY,
        openrouter: env.OPENROUTER_API_KEY,
        nvidia: env.NVIDIA_API_KEY,
        'opencode-zen': env.OPENCODE_ZEN_API_KEY ?? env.OPENCODE_GO_API_KEY,
        'opencode-go': env.OPENCODE_GO_API_KEY ?? env.OPENCODE_ZEN_API_KEY,
      },
      baseUrls: {
        anthropic: env.ANTHROPIC_BASE_URL?.replace(/\/+$/, ''),
        openai: env.OPENAI_BASE_URL,
        deepseek: env.DEEPSEEK_BASE_URL,
        openrouter: env.OPENROUTER_BASE_URL,
        nvidia: env.NVIDIA_BASE_URL,
        'opencode-zen': env.OPENCODE_ZEN_BASE_URL,
        'opencode-go': env.OPENCODE_GO_BASE_URL,
        ollama: env.OLLAMA_BASE_URL,
      },
      embeddings: {
        provider: env.EMBEDDINGS_PROVIDER,
        model: env.EMBEDDINGS_MODEL,
        baseUrl: env.EMBEDDINGS_BASE_URL?.replace(/\/+$/, ''),
        apiKey: env.EMBEDDINGS_API_KEY,
        dims: env.EMBEDDINGS_DIMS,
        batchSize: env.EMBEDDINGS_BATCH_SIZE,
        cacheDir: env.EMBEDDINGS_CACHE_DIR ?? join(homedir(), '.cache', 'transformers-js'),
        loadWaitMs: env.EMBEDDINGS_LOAD_WAIT_MS,
      },
      rag: {
        minConfidence: env.RAG_MIN_CONFIDENCE,
        topK: env.RAG_TOP_K,
        candidates: env.RAG_CANDIDATES,
        rrfK: env.RAG_RRF_K,
        chunkTokens: env.RAG_CHUNK_TOKENS,
        chunkOverlap: env.RAG_CHUNK_OVERLAP,
        memoryTurns: env.RAG_MEMORY_TURNS,
        privilegedRoles: env.RAG_PRIVILEGED_ROLES.split(',')
          .map((role) => role.trim())
          .filter((role) => role !== ''),
        externalDefault: env.RAG_EXTERNAL_DEFAULT,
        maxFileBytes: env.RAG_MAX_FILE_MB * 1024 * 1024,
        maxIterations: env.AGENT_MAX_ITERATIONS,
      },
      ocr: {
        languages: env.OCR_LANGUAGES,
        command: env.TESSERACT_CMD,
        tessdataPrefix: env.TESSDATA_PREFIX,
        timeoutMs: env.OCR_TIMEOUT_MS,
        maxPages: env.OCR_MAX_PAGES,
      },
      parse: {
        isolated: env.RAG_PARSE_ISOLATION,
        timeoutMs: env.RAG_PARSE_TIMEOUT_MS,
        maxOldSpaceMb: env.RAG_PARSE_MAX_MEMORY_MB,
      },
    },
  };
}
