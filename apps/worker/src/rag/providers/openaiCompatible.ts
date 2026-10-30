import OpenAI, { APIConnectionError, APIError } from 'openai';
import type { ChatCompletion, ChatCompletionCreateParamsNonStreaming, ChatCompletionMessageParam } from 'openai/resources/chat/completions';
import { headersOf, statusOf } from './errors.js';
import { ProviderError, parseRetryAfterMs, type AiProvider, type ChatMessage, type ChatRequest, type ChatResult, type ToolCall } from './types.js';

const CLIENT_USER_AGENT = 'platform-rag/1.0';
const SESSION_HEADER = 'x-opencode-session';
const STRUCTURED_UNSUPPORTED = /json_schema|response_format|structured|not support/i;
const REASONING_MODEL = /^(gpt-5|o[134])/i;

export interface OpenAiCompatibleOptions {
  baseURL: string;
  apiKey: string;
  identifyClient?: boolean;
  sendSessionHeader?: boolean;
  maxTokensParam?: 'max_tokens' | 'max_completion_tokens';
  supportsTemperature?: (model: string) => boolean;
  timeoutMs?: number;
  fetch?: typeof fetch;
}

export function openAiSupportsTemperature(model: string): boolean {
  return !REASONING_MODEL.test(model);
}

export function openAiErrorToProviderError(providerId: string, error: unknown): ProviderError {
  if (error instanceof APIConnectionError) return new ProviderError(`${providerId}: connection error: ${error.message}`, null);
  if (error instanceof APIError) {
    const status = statusOf(error);
    return new ProviderError(`${providerId}: HTTP ${status ?? '?'}: ${error.message}`, status, parseRetryAfterMs(headersOf(error)));
  }
  if (error instanceof ProviderError) return error;
  return new ProviderError(`${providerId}: ${error instanceof Error ? error.message : String(error)}`, null);
}

export class OpenAiCompatibleProvider implements AiProvider {
  private readonly client: OpenAI;
  private readonly sendSessionHeader: boolean;
  private readonly maxTokensParam: 'max_tokens' | 'max_completion_tokens';
  private readonly supportsTemperature: (model: string) => boolean;

  constructor(
    readonly id: string,
    options: OpenAiCompatibleOptions,
  ) {
    this.client = new OpenAI({
      baseURL: options.baseURL,
      apiKey: options.apiKey || 'none',
      maxRetries: 0,
      timeout: options.timeoutMs ?? 90_000,
      defaultHeaders: options.identifyClient ? { 'User-Agent': CLIENT_USER_AGENT } : undefined,
      fetch: options.fetch,
    });
    this.sendSessionHeader = options.sendSessionHeader ?? false;
    this.maxTokensParam = options.maxTokensParam ?? 'max_tokens';
    this.supportsTemperature = options.supportsTemperature ?? (() => true);
  }

  async chat(request: ChatRequest): Promise<ChatResult> {
    const source = request.jsonSchema
      ? request.messages.map((message, index) =>
          index === 0 && message.role === 'system'
            ? {
                ...message,
                content: `${message.content}\n\nRespond ONLY with a single JSON object matching this JSON Schema, no markdown, no prose:\n${JSON.stringify(request.jsonSchema!.schema)}`,
              }
            : message,
        )
      : request.messages;
    const base: ChatCompletionCreateParamsNonStreaming = {
      model: request.model,
      messages: source.map(toOpenAiMessage),
    };
    if (request.temperature !== undefined && this.supportsTemperature(request.model)) base.temperature = request.temperature;
    if (request.maxTokens) {
      if (this.maxTokensParam === 'max_completion_tokens') base.max_completion_tokens = request.maxTokens;
      else base.max_tokens = request.maxTokens;
    }
    if (request.tools && request.tools.length > 0) {
      base.tools = request.tools.map((tool) => ({
        type: 'function' as const,
        function: { name: tool.name, description: tool.description, parameters: tool.parameters },
      }));
    }
    const options = {
      headers: this.sendSessionHeader ? { [SESSION_HEADER]: request.sessionId ?? 'rag-default' } : undefined,
      signal: request.signal,
    };

    let response: ChatCompletion;
    try {
      if (request.jsonSchema) {
        try {
          response = await this.client.chat.completions.create(
            {
              ...base,
              response_format: {
                type: 'json_schema',
                json_schema: { name: request.jsonSchema.name, strict: true, schema: request.jsonSchema.schema },
              },
            },
            options,
          );
        } catch (error) {
          if (!(error instanceof APIError) || error instanceof APIConnectionError) throw error;
          if (error.status !== 400 && !STRUCTURED_UNSUPPORTED.test(error.message)) throw error;
          response = await this.client.chat.completions.create({ ...base, response_format: { type: 'json_object' } }, options);
        }
      } else {
        response = await this.client.chat.completions.create(base, options);
      }
    } catch (error) {
      throw openAiErrorToProviderError(this.id, error);
    }

    if (!response.choices || response.choices.length === 0) {
      const embedded = (response as unknown as { error?: { code?: number | string; message?: string } }).error;
      const code = Number(embedded?.code);
      const status = Number.isInteger(code) && (code === 429 || code >= 500) ? code : 429;
      throw new ProviderError(`${this.id}: ${embedded?.message ?? 'empty choices in response'}`, status);
    }
    const choice = response.choices[0]!;
    const toolCalls: ToolCall[] = (choice.message.tool_calls ?? [])
      .filter((call) => call.type === 'function')
      .map((call) => ({ id: call.id, name: call.function.name, arguments: call.function.arguments || '{}' }));
    const usage = response.usage;
    const cacheHit = (usage as unknown as { prompt_cache_hit_tokens?: number } | undefined)?.prompt_cache_hit_tokens;
    return {
      content: choice.message.content ?? null,
      toolCalls,
      usage: {
        promptTokens: usage?.prompt_tokens ?? 0,
        completionTokens: usage?.completion_tokens ?? 0,
        cachedTokens: cacheHit ?? usage?.prompt_tokens_details?.cached_tokens ?? 0,
      },
      finishReason: choice.finish_reason ?? 'stop',
      servedModel: response.model,
    };
  }
}

export function toOpenAiMessage(message: ChatMessage): ChatCompletionMessageParam {
  switch (message.role) {
    case 'system':
      return { role: 'system', content: message.content };
    case 'user':
      return { role: 'user', content: message.content };
    case 'assistant':
      if (message.toolCalls && message.toolCalls.length > 0) {
        return {
          role: 'assistant',
          content: message.content || null,
          tool_calls: message.toolCalls.map((call) => ({
            id: call.id,
            type: 'function' as const,
            function: { name: call.name, arguments: call.arguments },
          })),
        };
      }
      return { role: 'assistant', content: message.content };
    case 'tool':
      return { role: 'tool', content: message.content, tool_call_id: message.toolCallId ?? '' };
  }
}
