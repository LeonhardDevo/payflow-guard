import { describe, expect, it } from 'vitest';
import { createSilentLogger } from '../../src/logger.js';
import { SecretBox } from '../../src/rag/crypto.js';
import { HashEmbedder } from '../../src/rag/embeddings/hash.js';
import { LocalEmbedder, wordWindows, type FeatureExtractor } from '../../src/rag/embeddings/local.js';
import { EmbeddingsRegistry } from '../../src/rag/embeddings/registry.js';
import { RemoteEmbedder } from '../../src/rag/embeddings/remote.js';
import { calibrate, l2Normalize } from '../../src/rag/embeddings/types.js';
import { luhnValid, redactText } from '../../src/rag/privacy/redact.js';
import { ProviderKeys } from '../../src/rag/providers/keys.js';
import { AiSettingsService, MemorySettingsStore } from '../../src/rag/settings/store.js';
import { ragConfig } from './support/harness.js';

const logger = createSilentLogger();

function cosine(a: number[], b: number[]): number {
  return a.reduce((sum, value, index) => sum + value * (b[index] ?? 0), 0);
}

function registryFor(env: Record<string, string>, options: ConstructorParameters<typeof EmbeddingsRegistry>[4] = {}) {
  const config = ragConfig(env);
  const store = new MemorySettingsStore();
  const settings = new AiSettingsService(store, config);
  const keys = new ProviderKeys(store, config.providerKeys, new SecretBox('k'), logger);
  return { registry: new EmbeddingsRegistry(config, settings, keys, logger, options), settings };
}

const fakeExtractor = (dims: number, calls: string[][]): FeatureExtractor => {
  const extractor = async (texts: string[]) => {
    calls.push(texts);
    return { tolist: () => texts.map((text) => Array.from({ length: dims }, (_, index) => (index === text.length % dims ? 1 : 0.01))) };
  };
  return extractor;
};

describe('embeddings', () => {
  it('hash embeddings are deterministic, normalized and lexical', async () => {
    const embedder = new HashEmbedder();
    expect(embedder.modelId).toBe('hash:hash-384');
    const [a, b, c] = await embedder.embedDocuments(['Call the vendor back on the contract number', 'call vendor contract number', 'Kitchen cleaning on Fridays']);
    expect(a).toHaveLength(384);
    expect(Math.hypot(...a!)).toBeCloseTo(1, 6);
    expect(await embedder.embedQuery('Call the vendor back on the contract number')).toEqual(a);
    expect(cosine(a!, b!)).toBeGreaterThan(cosine(a!, c!));
    expect(new HashEmbedder(64).modelId).toBe('hash:hash-64');
  });

  it('normalizes and calibrates', () => {
    expect(l2Normalize([3, 4])).toEqual([0.6, 0.8]);
    expect(l2Normalize([0, 0])).toEqual([0, 0]);
    expect(calibrate(0.3, [0.05, 0.55])).toBeCloseTo(0.5);
    expect(calibrate(0.9, [0.05, 0.55])).toBe(1);
    expect(calibrate(0.5, [0.5, 0.5])).toBe(0.5);
  });

  it('splits long texts into overlapping word windows', () => {
    const text = Array.from({ length: 10 }, (_, index) => `w${index}`).join(' ');
    expect(wordWindows(text, 20, 5)).toEqual([text]);
    expect(wordWindows(text, 4, 1)).toEqual(['w0 w1 w2 w3', 'w3 w4 w5 w6', 'w6 w7 w8 w9']);
  });

  it('mean-pools local model windows and loads the model lazily once', async () => {
    const calls: string[][] = [];
    let loads = 0;
    const embedder = new LocalEmbedder('Xenova/paraphrase-multilingual-MiniLM-L12-v2', 'C:/tmp/cache', 2, async () => {
      loads += 1;
      return fakeExtractor(384, calls);
    });
    expect(loads).toBe(0);
    const long = Array.from({ length: 130 }, (_, index) => `word${index}`).join(' ');
    const [vector] = await embedder.embedDocuments([long]);
    await embedder.embedQuery('short');
    expect(loads).toBe(1);
    expect(calls.flat().length).toBe(4);
    expect(Math.hypot(...vector!)).toBeCloseTo(1, 6);
  });

  it('rejects a local model returning the wrong dimensions', async () => {
    const embedder = new LocalEmbedder('Xenova/paraphrase-multilingual-MiniLM-L12-v2', 'C:/tmp/cache', 8, async () => fakeExtractor(10, []));
    await expect(embedder.embedQuery('x')).rejects.toThrow(/10 dims/);
  });

  it('falls back to hash when the local model cannot load and builds a model once under concurrency', async () => {
    let loads = 0;
    const { registry } = registryFor(
      { EMBEDDINGS_PROVIDER: 'local' },
      {
        localLoader: async () => {
          loads += 1;
          await new Promise((resolve) => setTimeout(resolve, 20));
          throw new Error('offline');
        },
      },
    );
    const results = await Promise.all([registry.active(), registry.active(), registry.status()]);
    expect(loads).toBe(1);
    expect(results[0].modelId).toBe('hash:hash-384');
    expect(results[2]).toMatchObject({ degraded: true, configured: 'local:Xenova/paraphrase-multilingual-MiniLM-L12-v2' });
    expect(await registry.peek()).toMatchObject({ state: 'degraded' });
  });

  it('uses the settings choice and gives interactive callers hash while a model loads', async () => {
    let release!: () => void;
    const { registry, settings } = registryFor(
      {},
      {
        localLoader: () =>
          new Promise((resolve) => {
            release = () => resolve(fakeExtractor(384, []));
          }),
      },
    );
    expect((await registry.active()).modelId).toBe('hash:hash-384');
    await settings.update({ embeddings: { provider: 'local' } });
    const interactive = await registry.active(10);
    expect(interactive.modelId).toBe('hash:hash-384');
    expect((await registry.peek()).state).toBe('loading');
    release();
    expect((await registry.active()).modelId).toBe('local:Xenova/paraphrase-multilingual-MiniLM-L12-v2');
    expect((await registry.peek()).state).toBe('ready');
  });

  it('remote embedder batches requests and validates dimensions', async () => {
    const bodies: Array<{ input: string[] }> = [];
    const fetchImpl = (async (_url: string | URL | Request, init?: RequestInit) => {
      const body = JSON.parse(typeof init?.body === 'string' ? init.body : '{}') as { input: string[] };
      bodies.push(body);
      const data = body.input.map((_, index) => ({ index, embedding: [1, 0, 0, 0] }));
      return new Response(JSON.stringify({ data: data.reverse() }), { status: 200, headers: { 'content-type': 'application/json' } });
    }) as typeof fetch;
    const embedder = new RemoteEmbedder({ baseUrl: 'http://emb.test/v1', model: 'text-embedding-3-small', apiKey: 'k', dims: 4, batchSize: 2, fetch: fetchImpl });
    expect(embedder.modelId).toBe('openai-compatible:text-embedding-3-small');
    expect(await embedder.embedDocuments(['a', 'b', 'c'])).toHaveLength(3);
    expect(bodies.map((body) => body.input.length)).toEqual([2, 1]);
    const wrong = new RemoteEmbedder({ baseUrl: 'http://emb.test/v1', model: 'm', apiKey: null, dims: 8, fetch: fetchImpl });
    await expect(wrong.embedQuery('x')).rejects.toThrow(/EMBEDDINGS_DIMS/);
  });
});

describe('PII redaction', () => {
  it.each([
    ['Write to anna.petrova+hr@example.co.uk today', 'Write to [email] today'],
    ['Call +7 912 345-67-89 or (555) 123-4567', 'Call [phone ****6789] or [phone ****4567]'],
    ['IBAN DE89 3704 0044 0532 0130 00 please', 'IBAN [IBAN ****3000] please'],
    ['Card 4242 4242 4242 4242 expires', 'Card [card ****4242] expires'],
    ['Card 4111-1111-1111-1111', 'Card [card ****1111]'],
    ['Account 40817810099910004312 at the bank', 'Account [account ****4312] at the bank'],
  ])('masks personal identifiers keeping the last four: %s', (source, expected) => {
    expect(redactText(source)).toBe(expected);
  });

  it.each([
    'Vacation: 28 days, effective 2026-10-22.',
    'The limit is 1 000 000 RUB, invoice INV-2026-0042.',
    'Version 1.2.3 of policy [1] from 01.06.2025',
    '',
  ])('leaves dates, amounts and references alone: %s', (safe) => {
    expect(redactText(safe)).toBe(safe);
  });

  it('masks non-Luhn grouped numbers and treats long runs as accounts', () => {
    expect(redactText('Order 4242 4242 4242 4241')).toBe('Order [number ****4241]');
    expect(redactText('Card 4276 3800 1234 5678')).toBe('Card [number ****5678]');
    expect(redactText('Ref 1234567890123456')).toBe('Ref [account ****3456]');
  });

  it.each([
    ['Write to ivan.petrov@почта.рф now', 'Write to [email] now'],
    ['Почта: мария@пример.рус', 'Почта: [email]'],
    ['Phone 8 (916) 123-45-67', 'Phone [phone ****4567]'],
    ['Тел. +7 (495) 123-45-67', 'Тел. [phone ****4567]'],
    ['Passport ru 4510 123456', 'Passport ru [passport ****3456]'],
    ['Паспорт 45 10 123456 выдан', 'Паспорт [passport ****3456] выдан'],
    ['СНИЛС 112-233-445 95', 'СНИЛС [SNILS]'],
    ['ИНН 7707083893', 'ИНН [account ****3893]'],
  ])('masks Russian and Unicode identifiers: %s', (source, expected) => {
    expect(redactText(source)).toBe(expected);
  });

  it.each([
    'Invoice INV-2026-000123 is overdue',
    'Order ORD-2026-0001234567 shipped',
    'See ticket #2026-000123',
    'Счёт СЧ-2026-000123 оплачен',
    'Dates 2026-10-22 2026-10-23',
  ])('keeps invoice and order references: %s', (reference) => {
    expect(redactText(reference)).toBe(reference);
  });

  it('is idempotent', () => {
    const once = redactText('mail a@b.io, card 4242424242424242, phone +44 20 7946 0958');
    expect(redactText(once)).toBe(once);
    expect(once).not.toContain('4242424242424242');
    expect(once).not.toContain('a@b.io');
  });

  it('validates card numbers with the Luhn check', () => {
    expect(luhnValid('4242424242424242')).toBe(true);
    expect(luhnValid('4242424242424241')).toBe(false);
  });
});
