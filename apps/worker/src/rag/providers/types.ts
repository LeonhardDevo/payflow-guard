export type ChatRole = 'system' | 'user' | 'assistant' | 'tool';

export interface ToolCall {
  id: string;
  name: string;
  arguments: string;
}

export interface ChatMessage {
  role: ChatRole;
  content: string;
  toolCalls?: ToolCall[];
  toolCallId?: string;
  providerBlocks?: unknown[] | null;
  providerId?: string | null;
}

export interface ToolDefinition {
  name: string;
  description: string;
  parameters: Record<string, unknown>;
}

export interface ChatUsage {
  promptTokens: number;
  completionTokens: number;
  cachedTokens: number;
}

export interface JsonSchemaSpec {
  name: string;
  schema: Record<string, unknown>;
}

export interface ChatRequest {
  model: string;
  messages: ChatMessage[];
  tools?: ToolDefinition[];
  temperature?: number;
  maxTokens?: number;
  jsonSchema?: JsonSchemaSpec;
  sessionId?: string;
  signal?: AbortSignal;
  metadata?: Record<string, unknown>;
}

export interface ChatResult {
  content: string | null;
  toolCalls: ToolCall[];
  usage: ChatUsage;
  finishReason: string;
  servedModel?: string;
  providerBlocks?: unknown[] | null;
}

export interface AiProvider {
  readonly id: string;
  chat(request: ChatRequest): Promise<ChatResult>;
}

export class ProviderError extends Error {
  constructor(
    message: string,
    readonly status: number | null = null,
    readonly retryAfterMs: number | null = null,
  ) {
    super(message);
    this.name = 'ProviderError';
  }
}

export class ProviderNotConfiguredError extends ProviderError {
  constructor(readonly providerId: string) {
    super(`AI provider "${providerId}" is not configured (missing API key). Set it in the environment or in the AI admin settings.`);
    this.name = 'ProviderNotConfiguredError';
  }
}

export class ExternalProviderBlockedError extends ProviderError {
  constructor(readonly providerId: string) {
    super(`AI provider "${providerId}" is external and this workspace does not allow external providers.`);
    this.name = 'ExternalProviderBlockedError';
  }
}

export const EMPTY_USAGE: ChatUsage = Object.freeze({ promptTokens: 0, completionTokens: 0, cachedTokens: 0 });

export function parseRetryAfterMs(headers: { get(name: string): string | null } | null | undefined): number | null {
  if (!headers) return null;
  const ms = Number(headers.get('retry-after-ms'));
  if (Number.isFinite(ms) && ms >= 0 && headers.get('retry-after-ms') !== null) return ms;
  const value = headers.get('retry-after');
  if (value === null) return null;
  const seconds = Number(value);
  if (Number.isFinite(seconds) && seconds >= 0) return seconds * 1000;
  const date = Date.parse(value);
  return Number.isNaN(date) ? null : Math.max(0, date - Date.now());
}
