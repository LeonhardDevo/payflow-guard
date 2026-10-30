import Fastify, { type FastifyInstance } from 'fastify';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { draftRequestSchema, guardDraft, hasEmoji, mockDraft, TONE_SENTENCES, type DraftRequest } from '../../../src/rag/domain/payflow/draft.js';
import { ragHttp } from '../../../src/rag/http/index.js';
import type { ProviderBuilder } from '../../../src/rag/providers/factory.js';
import { MockProvider } from '../../../src/rag/providers/mock.js';
import type { AiProvider, ChatResult } from '../../../src/rag/providers/types.js';
import { forgetKnownIndexes } from '../../../src/rag/retrieval/indexes.js';
import type { RagService } from '../../../src/rag/service.js';
import { announceSkip, testDatabaseUrl } from '../../helpers/infra.js';
import { createRag, ragDatabase, signedHeaders, TEST_SECRET, type RagDatabase } from '../support/harness.js';

const WORKER_SECRET = 'worker-rag-pair-key-0123456789abcd';

function request(overrides: Partial<DraftRequest> = {}): DraftRequest {
  return draftRequestSchema.parse({
    workspace_id: 100,
    user: { id: 7, locale: 'en' },
    locale: 'en',
    tone: 'firm',
    ai_external_allowed: false,
    facts: {
      greeting_name: '[[GREETING]]',
      client_name: 'Helios Media Group',
      invoice_number: 'NS-2026-0118',
      amount_due: '$12,400.00',
      due_date: 'September 1, 2026',
      days_overdue: 52,
      invoice_link: 'http://app.test/i/abc123',
      company_name: 'Northstar Digital SIA',
      sender_name: '[[SENDER]]',
      late_fee: '$260.40',
      history: { avg_days_late: 18.38, reminders_sent: 6 },
    },
    base: {
      subject: 'Action required: invoice NS-2026-0118 — $12,400.00 overdue',
      body_markdown:
        'Dear [[GREETING]],\n\nInvoice **NS-2026-0118** for **$12,400.00** is now 52 days past its due date of September 1, 2026.\n\nPlease arrange payment within 5 business days: [pay online](http://app.test/i/abc123).\n\nRegards,\n[[SENDER]]\nNorthstar Digital SIA',
    },
    ...overrides,
  });
}

describe('reminder draft rules', () => {
  it('appends one deterministic tone sentence in the client locale and passes the guard', () => {
    for (const tone of ['friendly', 'neutral', 'firm', 'final'] as const) {
      for (const locale of ['en', 'ru'] as const) {
        const input = request({ tone, locale });
        const draft = mockDraft(input);
        expect(draft.subject).toBe(input.base.subject);
        expect(draft.body_markdown.endsWith(TONE_SENTENCES[tone][locale])).toBe(true);
        expect(guardDraft(draft, input)).toBeNull();
        expect(hasEmoji(TONE_SENTENCES[tone][locale])).toBe(false);
        expect(TONE_SENTENCES[tone][locale]).not.toMatch(/\d/);
      }
    }
  });

  it('rejects drafts that add numbers, drop facts or placeholders, use emoji or are too long', () => {
    const input = request();
    const base = mockDraft(input);
    expect(guardDraft({ ...base, body_markdown: `${base.body_markdown} Pay within 3 days.` }, input)).toBe('new_number');
    expect(guardDraft({ ...base, body_markdown: base.body_markdown.replace('[[SENDER]]', 'Mark') }, input)).toBe('missing_fact');
    expect(guardDraft({ subject: 'Invoice reminder', body_markdown: base.body_markdown.replaceAll('$12,400.00', 'the amount') }, input)).toBe('missing_fact');
    expect(guardDraft({ ...base, body_markdown: base.body_markdown.replace('http://app.test/i/abc123', 'our portal') }, input)).toBe('missing_fact');
    expect(guardDraft({ ...base, body_markdown: `${base.body_markdown} \u{1F642}` }, input)).toBe('emoji');
    expect(guardDraft({ ...base, body_markdown: `${base.body_markdown}${' word'.repeat(1200)}` }, input)).toBe('too_long');
    expect(guardDraft({ ...base, body_markdown: `${base.body_markdown} © Northstar` }, input)).toBeNull();
  });

  it('requires both placeholders and refuses real names in the facts', () => {
    expect(draftRequestSchema.safeParse({ ...request(), facts: { ...request().facts, greeting_name: 'Rachel Moore' } }).success).toBe(false);
    expect(draftRequestSchema.safeParse({ ...request(), facts: { ...request().facts, sender_name: 'Mark Ellis' } }).success).toBe(false);
  });
});

const database = await ragDatabase();
const skip = database ? '' : announceSkip('Postgres (reminder draft endpoint)', testDatabaseUrl());

class DraftLlm implements AiProvider {
  readonly id = 'deepseek';
  reply = '';
  calls = 0;
  async chat(): Promise<ChatResult> {
    this.calls++;
    return { content: this.reply, toolCalls: [], usage: { promptTokens: 5, completionTokens: 5, cachedTokens: 0 }, finishReason: 'stop' };
  }
}

describe.skipIf(!database)(`POST /v1/payflow/reminder-draft${skip}`, () => {
  let rag: RagService;
  let app: FastifyInstance;
  const llm = new DraftLlm();

  const post = (payload: unknown, service = 'web', secret = TEST_SECRET) => {
    const body = JSON.stringify(payload);
    return app.inject({ method: 'POST', url: '/v1/payflow/reminder-draft', payload: body, headers: signedHeaders('POST', '/v1/payflow/reminder-draft', body, service, secret) });
  };

  beforeAll(async () => {
    const mock = new MockProvider();
    const builder: ProviderBuilder = { mock, build: async (id) => (id === 'mock' ? mock : llm) };
    ({ rag } = createRag(database!, { providerBuilder: builder }));
    await rag.migrate();
    app = Fastify();
    await app.register(ragHttp({ rag, peerSecrets: { web: TEST_SECRET, worker: WORKER_SECRET } }));
    await app.ready();
  });

  afterAll(async () => {
    await app.close();
    forgetKnownIndexes();
    await (database as RagDatabase).drop();
  });

  it('accepts only the web caller', async () => {
    expect((await post(request(), 'worker', WORKER_SECRET)).statusCode).toBe(403);
    expect((await post(request(), 'web', WORKER_SECRET)).statusCode).toBe(401);
    expect((await post({ ...request(), tone: 'angry' })).statusCode).toBe(422);
  });

  it('returns the deterministic mock draft and records the usage purpose', async () => {
    await rag.settings.update({ provider: 'mock', model: 'mock-extractive-1' });
    const response = await post(request({ tone: 'final', locale: 'ru' }));
    expect(response.statusCode).toBe(200);
    const body = response.json<{ subject: string; body_markdown: string; provider: string; model: string; latency_ms: number }>();
    expect(body.provider).toBe('mock');
    expect(body.body_markdown.endsWith(TONE_SENTENCES.final.ru)).toBe(true);
    const usage = await rag.db.query<{ purpose: string; workspace_id: number }>(`SELECT purpose, workspace_id FROM ${rag.db.table('ai_usage')} WHERE purpose = 'reminder_draft'`);
    expect(usage.rows[0]).toEqual({ purpose: 'reminder_draft', workspace_id: 100 });
  });

  it('keeps external providers off unless the workspace allows them', async () => {
    await rag.settings.update({ provider: 'deepseek', model: 'deepseek-chat', fallback_model: null });
    llm.calls = 0;
    const blocked = await post(request({ ai_external_allowed: false }));
    expect(blocked.json<{ provider: string }>().provider).toBe('mock');
    expect(llm.calls).toBe(0);
  });

  it('uses a guarded LLM rewrite and falls back to the template when the guard fails', async () => {
    await rag.settings.update({ provider: 'deepseek', model: 'deepseek-chat', fallback_model: null });
    const good = {
      subject: 'Invoice NS-2026-0118 — $12,400.00 overdue',
      body_markdown: 'Dear [[GREETING]],\n\nInvoice NS-2026-0118 for $12,400.00 is still open. Please pay here: http://app.test/i/abc123\n\n[[SENDER]]',
    };
    llm.reply = JSON.stringify(good);
    const accepted = await post(request({ ai_external_allowed: true }));
    expect(accepted.json()).toMatchObject({ ...good, provider: 'deepseek' });

    llm.reply = JSON.stringify({ ...good, body_markdown: `${good.body_markdown} Pay within 48 hours or we will sue.` });
    const rejected = await post(request({ ai_external_allowed: true }));
    expect(rejected.json()).toMatchObject({ provider: 'mock', fallback: true });

    llm.reply = 'not json';
    expect((await post(request({ ai_external_allowed: true }))).json()).toMatchObject({ provider: 'mock', fallback: true });
  });
});
