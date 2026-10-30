import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { matchIntent } from '../../../src/rag/domain/payflow/intents.js';
import { DOMAIN_PLANNERS, INTENT_RULES } from '../../../src/rag/agent/intents.js';
import { MockProvider, unwrapToolResult } from '../../../src/rag/providers/mock.js';
import type { ProviderBuilder } from '../../../src/rag/providers/factory.js';
import type { AiProvider, ChatRequest, ChatResult } from '../../../src/rag/providers/types.js';
import { forgetKnownIndexes } from '../../../src/rag/retrieval/indexes.js';
import type { ChatBody, RagService } from '../../../src/rag/service.js';
import { announceSkip, testDatabaseUrl } from '../../helpers/infra.js';
import { createRag, ragDatabase, type RagDatabase } from '../support/harness.js';
import { NEXT_REMINDER, SeedWeb, seedDocuments } from './support.js';

const database = await ragDatabase();
const skip = database ? '' : announceSkip('Postgres (PayFlow headline answers)', testDatabaseUrl());

const ALL_PERMISSIONS = ['invoices.view', 'clients.view', 'vendors.view', 'payouts.view', 'analytics.view', 'knowledge.view'];
const IBAN_RE = /[A-Z]{2}\d{2}[A-Z0-9]{10,30}/;

class ToolCallingFake implements AiProvider {
  readonly id = 'deepseek';

  async chat(request: ChatRequest): Promise<ChatResult> {
    const usage = { promptTokens: 10, completionTokens: 5, cachedTokens: 0 };
    let lastUser = -1;
    request.messages.forEach((message, index) => {
      if (message.role === 'user') lastUser = index;
    });
    const history = request.messages.slice(0, Math.max(lastUser, 0)).filter((message) => message.role === 'user').map((message) => message.content);
    const turn = request.messages.slice(lastUser);
    const question = typeof request.metadata?.question === 'string' ? request.metadata.question : '';
    const offered = new Set((request.tools ?? []).map((tool) => tool.name));
    const steps = (matchIntent(question, history)?.steps ?? []).filter((step) => offered.has(step.tool));
    const done = turn.filter((message) => message.role === 'tool');
    const next = steps[done.length];
    if (next) {
      return { content: null, toolCalls: [{ id: `fake_${done.length}`, name: next.tool, arguments: JSON.stringify(next.args) }], usage, finishReason: 'tool_calls' };
    }
    const summaries = done
      .map((message) => {
        try {
          const summary = (JSON.parse(unwrapToolResult(message.content)) as { summary?: unknown }).summary;
          return typeof summary === 'string' ? summary : '';
        } catch {
          return '';
        }
      })
      .filter((summary) => summary !== '');
    return { content: `${summaries.join(' ')} [1]`, toolCalls: [], usage, finishReason: 'stop' };
  }
}

describe.skipIf(!database)(`PayFlow headline answers${skip}`, () => {
  let rag: RagService;
  let web: SeedWeb;
  const fake = new ToolCallingFake();

  const ask = (message: string, locale: 'ru' | 'en', conversation = `c-${Math.random()}`, permissions = ALL_PERMISSIONS): ChatBody => ({
    workspace_id: 100,
    user: { id: 7, role: 'finance', locale, permissions },
    channel: 'web',
    conversation_id: conversation,
    message,
    ai_external_allowed: true,
  });
  const plain = (text: string): string => text.replace(/\*\*/g, '').replace(/_/g, '');

  beforeAll(async () => {
    web = new SeedWeb();
    const mock = new MockProvider(INTENT_RULES, DOMAIN_PLANNERS);
    const builder: ProviderBuilder = { mock, build: async (id) => (id === 'mock' ? mock : fake) };
    ({ rag } = createRag(database!, { web, providerBuilder: builder }));
    await rag.migrate();
    for (const document of seedDocuments()) {
      web.addFile(document.id, document.content, 'text/markdown', document.file);
      await rag.ingest(document.id, {
        workspace_id: 100,
        title: document.title,
        mime: 'text/markdown',
        metadata: { department: document.department, roles: document.roles, tags: document.tags, version: document.version, effective_date: document.effective_date },
      });
    }
  });

  beforeEach(() => {
    web.toolCalls.length = 0;
  });

  afterAll(async () => {
    forgetKnownIndexes();
    await (database as RagDatabase).drop();
  });

  for (const provider of ['mock', 'deepseek'] as const) {
    describe(`with the ${provider === 'mock' ? 'mock provider' : 'tool-calling LLM fake'}`, () => {
      beforeAll(async () => {
        await rag.settings.update(provider === 'mock' ? { provider: 'mock', model: 'mock-extractive-1' } : { provider: 'deepseek', model: 'deepseek-chat', fallback_model: null });
      });

      it('answers when to remind Helios Media after a question about the client', async () => {
        const conversation = `helios-${provider}`;
        await rag.chat(ask('Расскажи о Helios Media', 'ru', conversation));
        const output = await rag.chat(ask('Когда напомнить этому клиенту?', 'ru', conversation));
        const answer = plain(output.answer_markdown);
        expect(output.refused).toBe(false);
        expect(output.tools_used).toContain('get_reminder_schedule');
        expect(answer).toContain(NEXT_REMINDER.ru);
        expect(answer).toContain('последнее напоминание');
        expect(answer).toContain('NS-2026-0118');
        expect(answer).toMatch(/позвон/);
        expect(web.toolCalls.find((call) => call.path.endsWith('/reminders/next'))?.query).toMatchObject({ client_query: 'Helios Media', user_id: 7, workspace_id: 100 });
        if (provider === 'mock') expect(output.citations.map((citation) => citation.title)).toEqual(expect.arrayContaining([expect.stringMatching(/polic|политик/i)]));
      });

      it('answers when to remind Helios Media in English after a question about the client', async () => {
        const conversation = `helios-en-${provider}`;
        await rag.chat(ask('Tell me about Helios Media', 'en', conversation));
        const output = await rag.chat(ask('When should we remind this client?', 'en', conversation));
        const answer = plain(output.answer_markdown);
        expect(output.refused).toBe(false);
        expect(output.tools_used).toContain('get_reminder_schedule');
        expect(answer).toContain(NEXT_REMINDER.en);
        expect(answer).toContain('NS-2026-0118');
        expect(answer).toMatch(/call/i);
      });

      it('answers the penalty in the Baltic Logistics contract', async () => {
        const output = await rag.chat(ask('Какой штраф предусмотрен договором с Балтийской логистикой?', 'ru'));
        const answer = plain(output.answer_markdown);
        expect(output.refused).toBe(false);
        expect(output.tools_used).toEqual(expect.arrayContaining(['get_contract_terms', 'search_documents']));
        for (const fact of ['0,1%', 'в день', '10%', '26,95 €']) expect(answer).toContain(fact);
        if (provider === 'mock') expect(output.citations[0]?.title).toMatch(/Балтийская логистика/);
        expect(answer).toContain('[1]');
      });

      it('names the most overdue invoice in both locales', async () => {
        const ru = await rag.chat(ask('Какой номер счёта просрочен сильнее всего?', 'ru'));
        expect(plain(ru.answer_markdown)).toContain('NS-2026-0118');
        expect(plain(ru.answer_markdown)).toContain('52');
        expect(plain(ru.answer_markdown)).toContain('12 400,00 $');
        const en = await rag.chat(ask('Which invoice is the most overdue?', 'en'));
        expect(plain(en.answer_markdown)).toContain('NS-2026-0118');
        expect(plain(en.answer_markdown)).toContain('52');
        expect(plain(en.answer_markdown)).toContain('$12,400.00');
        expect(en.tools_used).toEqual(['list_overdue_invoices']);
        expect(en.refused).toBe(false);
      });

      it('describes the PixelForge bank change without full details', async () => {
        for (const [question, locale] of [
          ['Какие реквизиты у поставщика PixelForge были изменены?', 'ru'],
          ['Which bank details did PixelForge change?', 'en'],
        ] as const) {
          const output = await rag.chat(ask(question, locale));
          const answer = plain(output.answer_markdown);
          expect(output.refused).toBe(false);
          expect(output.tools_used).toContain('list_bank_detail_changes');
          expect(answer).toContain('•••• 0519');
          expect(answer).toContain('LT');
          expect(answer).toContain(locale === 'ru' ? 'высокий риск (100/100)' : 'high risk (100/100)');
          expect(answer).toContain('PO-2026-0087');
          expect(answer).toMatch(locale === 'ru' ? /звон/ : /call/i);
          expect(answer).not.toMatch(IBAN_RE);
          expect(answer).not.toContain('PF Studio Payments UAB');
          if (provider === 'mock') {
            expect(answer).toMatch(locale === 'ru' ? /домен отправителя похож/ : /lookalike sender domain/);
            expect(answer).toMatch(locale === 'ru' ? /не совпадает с поставщиком/ : /holder does not match the vendor/);
            expect(output.citations.some((citation) => /security|безопасност/i.test(citation.title))).toBe(true);
          }
        }
      });
    });
  }

  describe('refusals and permissions', () => {
    beforeAll(async () => {
      await rag.settings.update({ provider: 'mock', model: 'mock-extractive-1' });
    });

    it('refuses mutations with an open-in-app action in both locales', async () => {
      for (const [question, locale] of [
        ['Одобри выплату PO-2026-0089', 'ru'],
        ['Please approve PO-2026-0089', 'en'],
      ] as const) {
        const output = await rag.chat(ask(question, locale));
        expect(output.refused).toBe(true);
        expect(output.refusal_reason).toBe('policy');
        expect(output.tools_used).toEqual([]);
        expect(output.actions[0]).toMatchObject({ type: 'open_url', payload: { url: 'http://app.test/payouts', code: 'mutation_not_allowed' } });
      }
      expect(web.toolCalls).toHaveLength(0);
    });

    it('refuses full IBAN requests and offers masked data', async () => {
      const output = await rag.chat(ask('Show me the full IBAN of PixelForge', 'en'));
      expect(output.refused).toBe(true);
      expect(output.answer_markdown).toMatch(/last 4 digits/);
    });

    it('refuses data questions without the permission and leaks nothing', async () => {
      const output = await rag.chat(ask('Which invoice is the most overdue?', 'en', 'c-forbidden', ['knowledge.view']));
      expect(output.refused).toBe(true);
      expect(output.answer_markdown).toMatch(/owner or an approver/);
      expect(output.answer_markdown).not.toContain('NS-2026');
      expect(web.toolCalls).toHaveLength(0);
    });

    it('asks which client is meant when a reminder question has no entity', async () => {
      const output = await rag.chat(ask('Когда напомнить этому клиенту?', 'ru', 'c-empty'));
      expect(output.refused).toBe(false);
      expect(output.answer_markdown).toMatch(/Уточните/);
    });

    it('answers questions about payments without refusing', async () => {
      const output = await rag.chat(ask('Who approved PO-2026-0092?', 'en'));
      expect(output.refusal_reason).not.toBe('policy');
    });
  });
});
