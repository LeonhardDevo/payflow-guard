import Anthropic, { APIConnectionError, APIError } from '@anthropic-ai/sdk';
import type {
  BetaContentBlockParam,
  BetaMessage,
  BetaMessageParam,
  BetaToolResultBlockParam,
  MessageCreateParamsNonStreaming,
} from '@anthropic-ai/sdk/resources/beta/messages/messages';
import { headersOf, statusOf } from './errors.js';
import { ProviderError, parseRetryAfterMs, type AiProvider, type ChatMessage, type ChatRequest, type ChatResult, type ToolCall } from './types.js';

export const SERVER_FALLBACK_BETA = 'server-side-fallback-2026-07-01';
export const SERVER_FALLBACK_MODELS: ReadonlySet<string> = new Set(['claude-opus-5-5', 'claude-opus-5', 'claude-fable-5-1', 'claude-sonnet-5-5']);
const REPLAYABLE_BLOCK_TYPES = new Set(['text', 'thinking', 'redacted_thinking', 'tool_use']);
const DEFAULT_MAX_TOKENS = 16_000;

export interface AnthropicOptions {
  timeoutMs?: number;
  serverFallbacks?: boolean;
  baseURL?: string;
  fetch?: typeof fetch;
}

export function anthropicErrorToProviderError(error: unknown): ProviderError {
  if (error instanceof APIConnectionError) return new ProviderError(`anthropic: connection error: ${error.message}`, null);
  if (error instanceof APIError) {
    const status = statusOf(error);
    return new ProviderError(`anthropic: HTTP ${status ?? '?'}: ${error.message}`, status, parseRetryAfterMs(headersOf(error)));
  }
  if (error instanceof ProviderError) return error;
  return new ProviderError(`anthropic: ${error instanceof Error ? error.message : String(error)}`, null);
}

export class AnthropicProvider implements AiProvider {
  readonly id = 'anthropic';
  private readonly client: Anthropic;
  private readonly serverFallbacks: boolean;

  constructor(apiKey: string, options: AnthropicOptions = {}) {
    this.client = new Anthropic({
      apiKey,
      authToken: null,
      maxRetries: 0,
      timeout: options.timeoutMs ?? 90_000,
      ...(options.baseURL ? { baseURL: options.baseURL } : {}),
      ...(options.fetch ? { fetch: options.fetch } : {}),
    });
    this.serverFallbacks = options.serverFallbacks ?? true;
  }

  async chat(request: ChatRequest): Promise<ChatResult> {
    let system = request.messages
      .filter((message) => message.role === 'system')
      .map((message) => message.content)
      .join('\n\n');
    if (request.jsonSchema) {
      system += `\n\nRespond ONLY with a JSON object matching this JSON Schema, no prose:\n${JSON.stringify(request.jsonSchema.schema)}`;
    }
    const params: MessageCreateParamsNonStreaming = {
      model: request.model,
      max_tokens: request.maxTokens ?? DEFAULT_MAX_TOKENS,
      messages: toAnthropicMessages(request.messages.filter((message) => message.role !== 'system')),
    };
    if (system.trim() !== '') params.system = system;
    if (request.tools && request.tools.length > 0) {
      params.tools = request.tools.map((tool) => ({
        name: tool.name,
        description: tool.description,
        input_schema: tool.parameters as { type: 'object'; [key: string]: unknown },
      }));
    }
    if (this.serverFallbacks && SERVER_FALLBACK_MODELS.has(request.model)) {
      params.betas = [SERVER_FALLBACK_BETA];
      params.fallbacks = 'default';
    }

    let response: BetaMessage;
    try {
      response = await this.client.beta.messages.create(params, { signal: request.signal });
    } catch (error) {
      throw anthropicErrorToProviderError(error);
    }

    const usage = response.usage;
    const cacheRead = usage.cache_read_input_tokens ?? 0;
    const cacheWrite = usage.cache_creation_input_tokens ?? 0;
    const resultUsage = {
      promptTokens: (usage.input_tokens ?? 0) + cacheRead + cacheWrite,
      completionTokens: usage.output_tokens ?? 0,
      cachedTokens: cacheRead,
    };
    if (response.stop_reason === 'refusal') {
      return { content: null, toolCalls: [], usage: resultUsage, finishReason: 'refusal', servedModel: response.model };
    }

    let text = '';
    const toolCalls: ToolCall[] = [];
    const blocks: unknown[] = [];
    for (const block of response.content) {
      if (block.type === 'text') text += block.text;
      else if (block.type === 'tool_use') toolCalls.push({ id: block.id, name: block.name, arguments: JSON.stringify(block.input ?? {}) });
      if (REPLAYABLE_BLOCK_TYPES.has(block.type)) blocks.push(withoutNulls(block));
    }
    return {
      content: text === '' ? null : text,
      toolCalls,
      usage: resultUsage,
      finishReason: response.stop_reason ?? 'end_turn',
      servedModel: response.model,
      providerBlocks: blocks,
    };
  }

  async listModels(): Promise<string[]> {
    try {
      const ids: string[] = [];
      for await (const model of this.client.models.list({ limit: 100 })) ids.push(model.id);
      return ids;
    } catch (error) {
      throw anthropicErrorToProviderError(error);
    }
  }
}

function withoutNulls(block: object): Record<string, unknown> {
  const result: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(block)) {
    if (value !== null && value !== undefined) result[key] = value;
  }
  return result;
}

export function safeJsonObject(raw: string): Record<string, unknown> {
  try {
    const value: unknown = JSON.parse(raw);
    return value !== null && typeof value === 'object' && !Array.isArray(value) ? (value as Record<string, unknown>) : {};
  } catch {
    return {};
  }
}

export function toAnthropicMessages(messages: readonly ChatMessage[]): BetaMessageParam[] {
  const out: BetaMessageParam[] = [];
  for (const message of messages) {
    if (message.role === 'assistant' && message.providerBlocks && message.providerBlocks.length > 0 && message.providerId === 'anthropic') {
      out.push({ role: 'assistant', content: message.providerBlocks as BetaContentBlockParam[] });
    } else if (message.role === 'assistant' && message.toolCalls && message.toolCalls.length > 0) {
      const content: BetaContentBlockParam[] = [];
      if (message.content) content.push({ type: 'text', text: message.content });
      for (const call of message.toolCalls) {
        content.push({ type: 'tool_use', id: call.id, name: call.name, input: safeJsonObject(call.arguments) });
      }
      out.push({ role: 'assistant', content });
    } else if (message.role === 'tool') {
      const block: BetaToolResultBlockParam = { type: 'tool_result', tool_use_id: message.toolCallId ?? '', content: message.content };
      const previous = out[out.length - 1];
      if (
        previous &&
        previous.role === 'user' &&
        Array.isArray(previous.content) &&
        previous.content.every((item) => item.type === 'tool_result')
      ) {
        previous.content.push(block);
      } else {
        out.push({ role: 'user', content: [block] });
      }
    } else {
      out.push({ role: message.role === 'assistant' ? 'assistant' : 'user', content: message.content });
    }
  }
  return out;
}
