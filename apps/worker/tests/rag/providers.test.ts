import { describe, expect, it } from 'vitest';
import { createSilentLogger } from '../../src/logger.js';
import { renderDocuments, type Source } from '../../src/rag/agent/sources.js';
import { SecretBox, deriveKey } from '../../src/rag/crypto.js';
import { INTENT_RULES } from '../../src/rag/agent/intents.js';
import { AnthropicProvider, SERVER_FALLBACK_BETA, toAnthropicMessages } from '../../src/rag/providers/anthropic.js';
import { ProviderFactory, type ProviderBuilder } from '../../src/rag/providers/factory.js';
import { ProviderKeys } from '../../src/rag/providers/keys.js';
import { LiveModels } from '../../src/rag/providers/liveModels.js';
import { AiManager, type UsageRecord, type UsageRecorder } from '../../src/rag/providers/manager.js';
import { MockProvider } from '../../src/rag/providers/mock.js';
import { OpenAiCompatibleProvider } from '../../src/rag/providers/openaiCompatible.js';
import { estimateCostUsd, priceFor } from '../../src/rag/providers/pricing.js';
import {
  ExternalProviderBlockedError,
  ProviderError,
  ProviderNotConfiguredError,
  type AiProvider,
  type ChatMessage,
  type ChatRequest,
  type ChatResult,
} from '../../src/rag/providers/types.js';
import { AiSettingsService, MemorySettingsStore, PROVIDER_KEYS_SETTING } from '../../src/rag/settings/store.js';
import { ragConfig } from './support/harness.js';

const logger = createSilentLogger();

class Recorder implements UsageRecorder {
  readonly records: UsageRecord[] = [];
  async record(record: UsageRecord): Promise<void> {
    this.records.push(record);
  }
}

class ScriptedProvider implements AiProvider {
  readonly calls: ChatRequest[] = [];
  constructor(
    private readonly script: Array<ChatResult | ProviderError>,
    readonly id = 'deepseek',
  ) {}
  async chat(request: ChatRequest): Promise<ChatResult> {
    this.calls.push(request);
    const next = this.script.length > 1 ? this.script.shift()! : this.script[0]!;
    if (next instanceof ProviderError) throw next;
    return next;
  }
}

function textResult(text: string, prompt = 10, completion = 5): ChatResult {
  return { content: text, toolCalls: [], usage: { promptTokens: prompt, completionTokens: completion, cachedTokens: 0 }, finishReason: 'stop' };
}

const rateLimited = (retryAfterMs: number | null = null) => new ProviderError('slow down', 429, retryAfterMs);

function keysFor(store = new MemorySettingsStore(), env: Record<string, string | undefined> = {}) {
  return new ProviderKeys(store, env, new SecretBox('unit-test-key'), logger);
}

async function managerWith(provider: AiProvider, fallback: string | null = 'fallback-model') {
  const config = ragConfig({ AI_RETRY_BASE_MS: '1000', AI_RETRY_MAX_MS: '8000' });
  const settings = new AiSettingsService(new MemorySettingsStore(), config);
  await settings.update({ provider: 'deepseek', model: 'primary-model', fallback_model: fallback });
  const recorder = new Recorder();
  const sleeps: number[] = [];
  const builder: ProviderBuilder = { mock: new MockProvider(), build: async () => provider };
  const manager = new AiManager(settings, builder, recorder, {
    retryBaseMs: 1000,
    retryMaxMs: 8000,
    maxTokens: 4096,
    logger,
    sleep: async (ms) => {
      sleeps.push(ms);
    },
  });
  return { manager, recorder, sleeps };
}

const request = (): ChatRequest => ({ model: '', messages: [{ role: 'user', content: 'hi' }] });

function jsonResponse(body: unknown, status = 200, headers: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json', ...headers } });
}

function capturingFetch(responses: Array<() => Response>) {
  const calls: Array<{ url: string; body: Record<string, unknown> | null; headers: Headers }> = [];
  const fetchImpl = (async (input: string | URL | Request, init?: RequestInit) => {
    const url = typeof input === 'string' ? input : input instanceof URL ? input.toString() : input.url;
    const raw = typeof init?.body === 'string' ? init.body : null;
    calls.push({ url, body: raw ? (JSON.parse(raw) as Record<string, unknown>) : null, headers: new Headers(init?.headers) });
    const next = responses.length > 1 ? responses.shift()! : responses[0]!;
    return next();
  }) as typeof fetch;
  return { fetchImpl, calls };
}

describe('pricing', () => {
  it('uses the longest matching key and skips free models', () => {
    expect(priceFor('claude-opus-5-5')).toEqual([4, 20, 0.2]);
    expect(priceFor('claude-opus-5')).toEqual([5, 25, 0.5]);
    expect(priceFor('anthropic/claude-sonnet-5-5')).toEqual([2, 10, 0.2]);
    expect(priceFor('z-ai/glm-5.2:free')).toBeNull();
    expect(priceFor('unknown-model')).toBeNull();
    const usage = { promptTokens: 1_000_000, completionTokens: 100_000, cachedTokens: 500_000 };
    expect(estimateCostUsd('claude-opus-5-5', usage)).toBeCloseTo(4.1, 6);
    expect(estimateCostUsd('mock-extractive-1', usage)).toBe(0);
  });
});

describe('secret box and provider keys', () => {
  it('encrypts with AES-256-GCM and rejects tampering or another key', () => {
    const box = new SecretBox('material');
    const token = box.encrypt('sk-secret-1234');
    expect(token.startsWith('v1:')).toBe(true);
    expect(token).not.toContain('sk-secret');
    expect(box.decrypt(token)).toBe('sk-secret-1234');
    expect(box.encrypt('same')).not.toBe(box.encrypt('same'));
    expect(() => new SecretBox('other').decrypt(token)).toThrow(/cannot be decrypted/);
    const tampered = `v1:${Buffer.from(Buffer.from(token.slice(3), 'base64url').map((byte, index) => (index === 20 ? byte ^ 1 : byte))).toString('base64url')}`;
    expect(() => box.decrypt(tampered)).toThrow();
    expect(deriveKey(Buffer.alloc(32, 7).toString('base64'))).toEqual(Buffer.alloc(32, 7));
  });

  it('prefers the stored key over the env key and never stores plaintext', async () => {
    const store = new MemorySettingsStore();
    const keys = keysFor(store, { deepseek: 'sk-env-1111' });
    expect(await keys.info('deepseek')).toEqual({ source: 'env', hint: '…1111' });
    expect(await keys.setKey('deepseek', '  sk-stored-2222  ')).toEqual({ source: 'settings', hint: '…2222' });
    expect(await keys.key('deepseek')).toBe('sk-stored-2222');
    expect(JSON.stringify([...store.values.entries()])).not.toContain('sk-stored-2222');
    await keys.setKey('deepseek', null);
    expect((await keys.info('deepseek')).source).toBe('env');
  });

  it('shares OpenCode keys, treats keyless providers as configured and ignores undecryptable keys', async () => {
    const store = new MemorySettingsStore();
    const keys = keysFor(store);
    expect(await keys.isConfigured('opencode-go')).toBe(false);
    await keys.setKey('opencode-zen', 'zen-key-9999');
    expect(await keys.key('opencode-go')).toBe('zen-key-9999');
    expect(await keys.isConfigured('mock')).toBe(true);
    expect(await keys.isConfigured('ollama')).toBe(true);
    expect((await keys.info('anthropic')).source).toBeNull();
    await store.setRaw(PROVIDER_KEYS_SETTING, { anthropic: 'v1:not-a-token' });
    expect(await keys.key('anthropic')).toBeNull();
  });
});

function sourcesMessage(question: string): ChatMessage {
  const sources: Source[] = [
    { ref: 1, documentId: 10, title: 'Callback policy', content: 'Call the vendor on the number from the signed contract. Never use the number from the e-mail.', chunkId: 1, page: null, section: null, version: null, effectiveDate: null, score: 1, confidence: 1, origin: 'retrieval' },
    { ref: 2, documentId: 11, title: 'Kitchen rules', content: 'The kitchen is cleaned on Fridays.', chunkId: 2, page: null, section: null, version: null, effectiveDate: null, score: 1, confidence: 1, origin: 'retrieval' },
  ];
  return { role: 'user', content: `${renderDocuments(sources)}\n\nQuestion: ${question}` };
}

describe('mock provider', () => {
  it('answers extractively with citations', async () => {
    const result = await new MockProvider().chat({ model: '', messages: [sourcesMessage('Which number should I call the vendor on?')], metadata: { locale: 'en', question: 'Which number should I call the vendor on?' } });
    expect(result.content).toContain('[1]');
    expect(result.content).toContain('signed contract');
    expect(result.content).not.toContain('kitchen');
    expect(result.content?.startsWith('According to the approved documents:')).toBe(true);
  });

  it('localizes the intro and reports not found', async () => {
    const ru = await new MockProvider().chat({ model: '', messages: [sourcesMessage('vendor contract number')], metadata: { locale: 'ru', question: 'vendor contract number' } });
    expect(ru.content?.startsWith('Согласно утверждённым документам:')).toBe(true);
    const none = await new MockProvider().chat({ model: '', messages: [sourcesMessage('weather in Moscow')], metadata: { locale: 'en', question: 'weather in Moscow' } });
    expect(none.content).toBe('[[NOT_FOUND]]');
    expect((await new MockProvider().chat({ model: '', messages: [], metadata: { ping: true } })).content).toBe('OK');
  });

  it('routes intents to offered tools once per turn', async () => {
    const provider = new MockProvider(INTENT_RULES);
    const tools = [{ name: 'search_documents', description: '', parameters: {} }];
    const routed = await provider.chat({ model: '', messages: [{ role: 'user', content: 'найди регламент смены реквизитов' }], tools, metadata: { question: 'найди регламент смены реквизитов' } });
    expect(routed.toolCalls).toEqual([{ id: 'mock_search_documents_1', name: 'search_documents', arguments: JSON.stringify({ query: 'регламент смены реквизитов' }) }]);
    const notOffered = await provider.chat({ model: '', messages: [{ role: 'user', content: 'find the policy' }], tools: [], metadata: { question: 'find the policy' } });
    expect(notOffered.toolCalls).toEqual([]);
    const afterTool = await provider.chat({
      model: '',
      messages: [{ role: 'user', content: 'find the policy' }, { role: 'assistant', content: '', toolCalls: routed.toolCalls }, { role: 'tool', content: 'No matching approved documents were found.', toolCallId: 'x' }],
      tools,
      metadata: { question: 'find the policy' },
    });
    expect(afterTool.toolCalls).toEqual([]);
  });

  it('uses data tool summaries', async () => {
    const result = await new MockProvider().chat({
      model: '',
      messages: [{ role: 'user', content: 'pending approvals' }, { role: 'tool', content: JSON.stringify({ summary: '2 approvals wait for you.' }), toolCallId: 't' }],
      metadata: { locale: 'en', question: 'pending approvals' },
    });
    expect(result.content).toContain('Data from connected systems:');
    expect(result.content).toContain('2 approvals wait for you.');
  });
});

describe('provider manager', () => {
  it('records usage and cost on success', async () => {
    const provider = new ScriptedProvider([textResult('ok', 1000, 100)]);
    const { manager, recorder } = await managerWith(provider);
    const managed = await manager.chat(request(), { purpose: 'chat', workspaceId: 7, requestId: 'req-1' });
    expect([managed.provider, managed.model, managed.attempts]).toEqual(['deepseek', 'primary-model', 1]);
    expect(provider.calls[0]!.temperature).toBe(0.2);
    expect(provider.calls[0]!.sessionId).toBe('ws-7-chat');
    expect(recorder.records).toHaveLength(1);
    expect(recorder.records[0]).toMatchObject({ workspaceId: 7, purpose: 'chat', ok: true, requestId: 'req-1', costUsd: 0 });
  });

  it.each([403, 404])('switches to the fallback model on %i', async (status) => {
    const provider = new ScriptedProvider([new ProviderError('gone', status), textResult('ok')]);
    const { manager, sleeps } = await managerWith(provider);
    const managed = await manager.chat(request(), { purpose: 'chat' });
    expect(provider.calls.map((call) => call.model)).toEqual(['primary-model', 'fallback-model']);
    expect(managed.model).toBe('fallback-model');
    expect(sleeps).toEqual([]);
  });

  it('backs off on 429 honouring retry-after and falls back after three attempts', async () => {
    const provider = new ScriptedProvider([rateLimited(), rateLimited(3000), rateLimited(), textResult('ok')]);
    const { manager, sleeps } = await managerWith(provider);
    const managed = await manager.chat(request(), { purpose: 'chat' });
    expect(provider.calls.map((call) => call.model)).toEqual(['primary-model', 'primary-model', 'primary-model', 'fallback-model']);
    expect(sleeps).toEqual([1000, 3000]);
    expect(managed.attempts).toBe(4);
  });

  it('gives up after six attempts and records the failure', async () => {
    const provider = new ScriptedProvider([rateLimited()]);
    const { manager, recorder, sleeps } = await managerWith(provider);
    await expect(manager.chat(request(), { purpose: 'chat' })).rejects.toThrow(ProviderError);
    expect(provider.calls).toHaveLength(6);
    expect(sleeps).toHaveLength(4);
    expect(recorder.records.at(-1)?.ok).toBe(false);
  });

  it('retries 429 up to the maximum without a fallback', async () => {
    const provider = new ScriptedProvider([rateLimited()]);
    const { manager, sleeps } = await managerWith(provider, null);
    await expect(manager.chat(request(), { purpose: 'chat' })).rejects.toThrow(ProviderError);
    expect(provider.calls).toHaveLength(6);
    expect(sleeps).toEqual([1000, 2000, 4000, 8000, 8000]);
  });

  it('does not retry client errors and limits transient retries', async () => {
    const badRequest = new ScriptedProvider([new ProviderError('bad', 400)]);
    await expect((await managerWith(badRequest)).manager.chat(request(), { purpose: 'chat' })).rejects.toThrow('bad');
    expect(badRequest.calls).toHaveLength(1);
    const flaky = new ScriptedProvider([new ProviderError('down', 503)]);
    await expect((await managerWith(flaky, null)).manager.chat(request(), { purpose: 'chat' })).rejects.toThrow('down');
    expect(flaky.calls).toHaveLength(3);
  });

  it('reports an unconfigured provider and blocks external providers when the workspace forbids them', async () => {
    const config = ragConfig();
    const store = new MemorySettingsStore();
    const settings = new AiSettingsService(store, config);
    await settings.update({ provider: 'anthropic' });
    const recorder = new Recorder();
    const manager = new AiManager(settings, new ProviderFactory(config, keysFor(store)), recorder, { retryBaseMs: 1, retryMaxMs: 1, maxTokens: 64, logger });
    await expect(manager.chat(request(), { purpose: 'chat' })).rejects.toThrow(ProviderNotConfiguredError);
    await expect(manager.chat(request(), { purpose: 'chat', allowExternal: false })).rejects.toThrow(ExternalProviderBlockedError);
    expect(recorder.records.every((record) => !record.ok)).toBe(true);
  });

  it('redacts PII before messages leave for an external provider but not for the mock', async () => {
    const external = new ScriptedProvider([textResult('ok')]);
    const { manager } = await managerWith(external);
    const sensitive: ChatRequest = {
      model: '',
      messages: [
        { role: 'system', content: 'system stays' },
        { role: 'user', content: 'Mail karen@blueridge.example, call +1 828 555 0199, IBAN LT09 1234 5678 9012 8839, card 4111 1111 1111 1111' },
      ],
    };
    await manager.chat(sensitive, { purpose: 'chat' });
    const sent = external.calls[0]!.messages[1]!.content;
    expect(sent).toBe('Mail [email], call [phone ****0199], IBAN [IBAN ****8839], card [card ****1111]');
    expect(external.calls[0]!.messages[0]!.content).toBe('system stays');

    const mock = new ScriptedProvider([textResult('ok')], 'mock');
    const local = await managerWith(mock);
    await local.manager.chat(sensitive, { purpose: 'chat', provider: mock });
    expect(mock.calls[0]!.messages[1]!.content).toContain('karen@blueridge.example');
  });

  it('defaults to the mock provider and answers the connectivity ping', async () => {
    const config = ragConfig();
    const store = new MemorySettingsStore();
    const settings = new AiSettingsService(store, config);
    const manager = new AiManager(settings, new ProviderFactory(config, keysFor(store)), new Recorder(), { retryBaseMs: 1, retryMaxMs: 1, maxTokens: 64, logger });
    const managed = await manager.chat({ ...request(), metadata: { ping: true } }, { purpose: 'test' });
    expect([managed.provider, managed.model, managed.result.content]).toEqual(['mock', 'mock-extractive-1', 'OK']);
  });
});

describe('OpenAI-compatible provider', () => {
  it('sends tools, parses tool calls and usage including cache hits', async () => {
    const { fetchImpl, calls } = capturingFetch([
      () =>
        jsonResponse({
          id: 'c1',
          object: 'chat.completion',
          created: 1,
          model: 'deepseek-chat-served',
          choices: [{ index: 0, finish_reason: 'tool_calls', message: { role: 'assistant', content: null, tool_calls: [{ id: 'call_1', type: 'function', function: { name: 'search_documents', arguments: '{"query":"x"}' } }] } }],
          usage: { prompt_tokens: 100, completion_tokens: 20, total_tokens: 120, prompt_cache_hit_tokens: 40 },
        }),
    ]);
    const provider = new OpenAiCompatibleProvider('opencode-go', { baseURL: 'http://llm.test/v1', apiKey: 'k', sendSessionHeader: true, identifyClient: true, fetch: fetchImpl });
    const result = await provider.chat({
      model: 'deepseek-chat',
      messages: [{ role: 'system', content: 's' }, { role: 'user', content: 'q' }, { role: 'assistant', content: '', toolCalls: [{ id: 'a', name: 't', arguments: '{}' }] }, { role: 'tool', content: 'r', toolCallId: 'a' }],
      tools: [{ name: 'search_documents', description: 'd', parameters: { type: 'object' } }],
      temperature: 0.3,
      maxTokens: 100,
      sessionId: 'ws-1-chat',
    });
    expect(result.toolCalls).toEqual([{ id: 'call_1', name: 'search_documents', arguments: '{"query":"x"}' }]);
    expect(result.usage).toEqual({ promptTokens: 100, completionTokens: 20, cachedTokens: 40 });
    expect(result.servedModel).toBe('deepseek-chat-served');
    expect(calls[0]!.url).toBe('http://llm.test/v1/chat/completions');
    expect(calls[0]!.headers.get('x-opencode-session')).toBe('ws-1-chat');
    expect(calls[0]!.body).toMatchObject({ model: 'deepseek-chat', temperature: 0.3, max_tokens: 100 });
    expect((calls[0]!.body!.messages as unknown[])[3]).toEqual({ role: 'tool', content: 'r', tool_call_id: 'a' });
  });

  it('falls back from json_schema to json_object and skips temperature for reasoning models', async () => {
    const { fetchImpl, calls } = capturingFetch([
      () => jsonResponse({ error: { message: 'response_format json_schema is not supported' } }, 400),
      () => jsonResponse({ id: 'c', object: 'chat.completion', created: 1, model: 'gpt-5-mini', choices: [{ index: 0, finish_reason: 'stop', message: { role: 'assistant', content: '{}' } }] }),
    ]);
    const provider = new OpenAiCompatibleProvider('openai', {
      baseURL: 'http://llm.test/v1',
      apiKey: 'k',
      maxTokensParam: 'max_completion_tokens',
      supportsTemperature: (model) => !model.startsWith('gpt-5'),
      fetch: fetchImpl,
    });
    const result = await provider.chat({ model: 'gpt-5-mini', messages: [{ role: 'system', content: 's' }], jsonSchema: { name: 'x', schema: { type: 'object' } }, temperature: 0.2, maxTokens: 50 });
    expect(result.content).toBe('{}');
    expect((calls[0]!.body!.response_format as { type: string }).type).toBe('json_schema');
    expect((calls[1]!.body!.response_format as { type: string }).type).toBe('json_object');
    expect(calls[1]!.body).not.toHaveProperty('temperature');
    expect(calls[1]!.body).toMatchObject({ max_completion_tokens: 50 });
  });

  it('maps HTTP errors with retry-after and empty choices to provider errors', async () => {
    const limited = new OpenAiCompatibleProvider('deepseek', {
      baseURL: 'http://llm.test',
      apiKey: 'k',
      fetch: capturingFetch([() => jsonResponse({ error: { message: 'slow' } }, 429, { 'retry-after': '7' })]).fetchImpl,
    });
    await expect(limited.chat({ model: 'm', messages: [] })).rejects.toMatchObject({ status: 429, retryAfterMs: 7000 });
    const empty = new OpenAiCompatibleProvider('openrouter', {
      baseURL: 'http://llm.test',
      apiKey: 'k',
      fetch: capturingFetch([() => jsonResponse({ id: 'c', object: 'chat.completion', created: 1, model: 'm', choices: [], error: { code: 502, message: 'upstream' } })]).fetchImpl,
    });
    await expect(empty.chat({ model: 'm', messages: [] })).rejects.toMatchObject({ status: 502 });
  });
});

describe('Anthropic provider', () => {
  const anthropicMessage = (content: unknown[], stopReason = 'tool_use') => ({
    id: 'msg_1',
    type: 'message',
    role: 'assistant',
    model: 'claude-opus-5-5',
    content,
    stop_reason: stopReason,
    stop_sequence: null,
    usage: { input_tokens: 50, output_tokens: 10, cache_read_input_tokens: 20, cache_creation_input_tokens: 5 },
  });

  it('sends server-side fallbacks, no sampling parameters, and parses tool use and usage', async () => {
    const { fetchImpl, calls } = capturingFetch([
      () =>
        jsonResponse(
          anthropicMessage([
            { type: 'thinking', thinking: '', signature: 'sig' },
            { type: 'text', text: 'Searching.' },
            { type: 'tool_use', id: 'toolu_1', name: 'search_documents', input: { query: 'callback' } },
          ]),
        ),
    ]);
    const provider = new AnthropicProvider('sk-ant', { baseURL: 'http://anthropic.test', fetch: fetchImpl });
    const result = await provider.chat({
      model: 'claude-opus-5-5',
      messages: [{ role: 'system', content: 'rules' }, { role: 'user', content: 'q' }],
      tools: [{ name: 'search_documents', description: 'd', parameters: { type: 'object', properties: {} } }],
      temperature: 0.2,
      maxTokens: 300,
    });
    expect(result.toolCalls).toEqual([{ id: 'toolu_1', name: 'search_documents', arguments: '{"query":"callback"}' }]);
    expect(result.content).toBe('Searching.');
    expect(result.usage).toEqual({ promptTokens: 75, completionTokens: 10, cachedTokens: 20 });
    expect(result.providerBlocks).toHaveLength(3);
    const body = calls[0]!.body!;
    expect(body).toMatchObject({ model: 'claude-opus-5-5', max_tokens: 300, system: 'rules', fallbacks: 'default' });
    expect(body).not.toHaveProperty('temperature');
    expect(body).not.toHaveProperty('thinking');
    expect(calls[0]!.headers.get('anthropic-beta')).toContain(SERVER_FALLBACK_BETA);
  });

  it('omits fallbacks for models without server-side fallback support', async () => {
    const { fetchImpl, calls } = capturingFetch([() => jsonResponse(anthropicMessage([{ type: 'text', text: 'OK' }], 'end_turn'))]);
    const result = await new AnthropicProvider('sk-ant', { baseURL: 'http://anthropic.test', fetch: fetchImpl }).chat({ model: 'claude-haiku-5-5', messages: [{ role: 'user', content: 'ping' }] });
    expect(result.content).toBe('OK');
    expect(calls[0]!.body).not.toHaveProperty('fallbacks');
  });

  it('replays native blocks and groups tool results', () => {
    const blocks = [{ type: 'thinking', thinking: '', signature: 's' }, { type: 'tool_use', id: 't1', name: 'x', input: {} }];
    const messages = toAnthropicMessages([
      { role: 'user', content: 'q' },
      { role: 'assistant', content: '', toolCalls: [{ id: 't1', name: 'x', arguments: '{}' }, { id: 't2', name: 'y', arguments: 'bad' }], providerBlocks: blocks, providerId: 'anthropic' },
      { role: 'tool', content: 'r1', toolCallId: 't1' },
      { role: 'tool', content: 'r2', toolCallId: 't2' },
      { role: 'assistant', content: 'other', toolCalls: [{ id: 't3', name: 'z', arguments: 'bad' }], providerId: 'openai' },
    ]);
    expect(messages[1]).toEqual({ role: 'assistant', content: blocks });
    expect(messages[2]).toEqual({
      role: 'user',
      content: [
        { type: 'tool_result', tool_use_id: 't1', content: 'r1' },
        { type: 'tool_result', tool_use_id: 't2', content: 'r2' },
      ],
    });
    expect(messages[3]).toEqual({ role: 'assistant', content: [{ type: 'text', text: 'other' }, { type: 'tool_use', id: 't3', name: 'z', input: {} }] });
  });

  it('surfaces refusals and maps errors', async () => {
    const refused = new AnthropicProvider('sk-ant', { baseURL: 'http://anthropic.test', fetch: capturingFetch([() => jsonResponse(anthropicMessage([], 'refusal'))]).fetchImpl });
    expect((await refused.chat({ model: 'claude-sonnet-5-5', messages: [{ role: 'user', content: 'x' }] })).finishReason).toBe('refusal');
    const missing = new AnthropicProvider('sk-ant', {
      baseURL: 'http://anthropic.test',
      fetch: capturingFetch([() => jsonResponse({ type: 'error', error: { type: 'not_found_error', message: 'model' } }, 404)]).fetchImpl,
    });
    await expect(missing.chat({ model: 'claude-x', messages: [{ role: 'user', content: 'x' }] })).rejects.toMatchObject({ status: 404 });
  });
});

describe('live model lists', () => {
  it('filters, sorts and caches models for 10 minutes', async () => {
    const config = ragConfig();
    let now = 0;
    const { fetchImpl, calls } = capturingFetch([
      () =>
        jsonResponse({
          data: [
            { id: 'paid/model', pricing: { prompt: '0.1', completion: '0.2' }, context_length: 1000, supported_parameters: ['tools'] },
            { id: 'free/model:free', context_length: 2000, supported_parameters: ['structured_outputs'] },
            { id: 'text-embedding-3', pricing: { prompt: '0', completion: '0' } },
          ],
        }),
    ]);
    const live = new LiveModels(config, keysFor(), { fetch: fetchImpl, clock: () => now });
    const models = await live.models('openrouter');
    expect(models.map((model) => [model.id, model.free, model.structured])).toEqual([
      ['free/model:free', true, true],
      ['paid/model', false, false],
    ]);
    now = 9 * 60_000;
    await live.models('openrouter');
    expect(calls).toHaveLength(1);
    now = 11 * 60_000;
    await live.models('openrouter');
    expect(calls).toHaveLength(2);
    expect(await live.models('deepseek')).toEqual([]);
    expect((await live.models('mock'))[0]!.id).toBe('mock-extractive-1');
  });

  it('lists Anthropic models through the SDK lister only with a key', async () => {
    const store = new MemorySettingsStore();
    const keys = keysFor(store);
    const listed: string[] = [];
    const live = new LiveModels(ragConfig(), keys, {
      anthropicLister: async (key) => {
        listed.push(key);
        return ['claude-opus-5-5', 'claude-haiku-5-5'];
      },
    });
    expect(await live.models('anthropic')).toEqual([]);
    await keys.setKey('anthropic', 'sk-ant-1234');
    live.forget('anthropic');
    expect((await live.models('anthropic')).map((model) => model.id)).toEqual(['claude-haiku-5-5', 'claude-opus-5-5']);
    expect(listed).toEqual(['sk-ant-1234']);
  });
});
