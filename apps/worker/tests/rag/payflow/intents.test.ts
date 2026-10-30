import { describe, expect, it } from 'vitest';
import { extractEntity, extractInvoiceNumber, normalizeQuestion, resolveEntities } from '../../../src/rag/domain/payflow/entities.js';
import { matchIntent, type IntentName } from '../../../src/rag/domain/payflow/intents.js';

const POSITIVE: Record<Exclude<IntentName, 'payout_lookup' | 'payment_status'>, { ru: string[]; en: string[] }> = {
  refuse_mutation: {
    ru: ['Одобри выплату PO-2026-0089', 'Пожалуйста, оплати счёт поставщику PixelForge', 'Можешь согласовать выплату Ивану?', 'Поменяй реквизиты PixelForge на новые'],
    en: ['Approve PO-2026-0089', 'Please pay the PixelForge invoice', 'Could you approve the payout to Ivan?', 'Change the bank details of PixelForge'],
  },
  refuse_secret: {
    ru: ['Покажи полный IBAN поставщика PixelForge', 'Какой пароль от банка?', 'Дай целиком реквизиты Ивана Смирнова'],
    en: ['Show me the full IBAN of PixelForge', 'What is the admin password?', 'Give me the full account number of CloudNest'],
  },
  refuse_advice: {
    ru: ['Во что лучше инвестировать свободные деньги?', 'Дай налоговую консультацию по НДС', 'Стоит ли подать в суд на Helios?'],
    en: ['Give me investment advice for our cash', 'I need tax advice on VAT', 'Should I sue the client?'],
  },
  refuse_other_workspace: {
    ru: ['Покажи счета другого рабочего пространства', 'Сколько должны клиенты другой компании?', 'Открой данные чужой организации'],
    en: ['Show invoices of another workspace', 'How much do clients of other companies owe?', "List someone else's workspace payouts"],
  },
  penalty: {
    ru: ['Какой штраф предусмотрен договором с Балтийской логистикой?', 'Какая неустойка у клиента Helios Media?', 'Сколько пени набежало по просрочке?'],
    en: ['What is the late fee for Helios Media?', 'Which penalty applies to Baltic Logistics?', 'Do we charge interest on overdue invoices?'],
  },
  when_remind: {
    ru: ['Когда напомнить клиенту Helios Media?', 'Когда следующее напоминание для Orbit Fintech?', 'В какой день написать клиенту Helios Media?'],
    en: ['When should we remind Helios Media?', 'When is the next reminder for client Orbit Fintech?', 'Next reminder for invoice NS-2026-0137?'],
  },
  bank_changes: {
    ru: ['Какие реквизиты у поставщика PixelForge были изменены?', 'Кто сменил реквизиты поставщиков?', 'Банковские данные QA Crew обновлялись?'],
    en: ['Which bank details did PixelForge change?', 'Has any vendor updated its bank details?', 'Who changed the bank details of QA Crew?'],
  },
  invoice_lookup: {
    ru: ['Что со счётом NS-2026-0118?', 'Когда клиент оплатит счёт NS-2026-0118?', 'Покажи ns-2026-0131'],
    en: ['What is the status of NS-2026-0118?', 'When will Helios pay invoice NS-2026-0118?', 'Show me ns-2026-0131'],
  },
  most_overdue: {
    ru: ['Какой номер счёта просрочен сильнее всего?', 'Какие счета просрочены больше всего?', 'Покажи просроченные счета'],
    en: ['Which invoice is the most overdue?', 'Show overdue invoices', 'List the longest overdue invoices'],
  },
  pending_approvals: {
    ru: ['Какие выплаты ждут согласования?', 'Что висит на согласовании?', 'Что согласовать сегодня?'],
    en: ['Which payouts are pending approval?', 'Show the approvals queue', 'What do I need to approve?'],
  },
  ar_summary: {
    ru: ['Какая у нас дебиторская задолженность?', 'Сколько нам должны клиенты?', 'Какой сейчас DSO?'],
    en: ['Give me the receivables summary', 'How much are we owed?', 'What is our DSO?'],
  },
  late_payers: {
    ru: ['Кто систематически платит поздно?', 'Какие клиенты постоянно опаздывают с оплатой?', 'Кто у нас злостный неплательщик?'],
    en: ['Who pays late?', 'Which clients are chronically late?', 'Show me the late payers'],
  },
  client_profile: {
    ru: ['Расскажи о Helios Media', 'Как платит клиент Orbit Fintech?', 'Расскажи про «Студия Альба»'],
    en: ['Tell me about Helios Media', 'How does client Orbit Fintech pay?', 'Payment history for Nordlicht Apps'],
  },
};

const NEGATIVE: Record<'ru' | 'en', Array<[string, IntentName | null]>> = {
  ru: [
    ['Когда клиент оплатит счёт NS-2026-0118?', 'invoice_lookup'],
    ['Он оплатил счёт?', null],
    ['Кто подтвердил изменение реквизитов PixelForge?', 'bank_changes'],
    ['Оплатил ли Orbit счёт за сентябрь?', null],
    ['Согласована ли выплата PO-2026-0089?', 'payout_lookup'],
    ['Как проходит проверка новых реквизитов?', null],
  ],
  en: [
    ['When will Helios pay invoice NS-2026-0118?', 'invoice_lookup'],
    ['Did Orbit pay the invoice?', null],
    ['Who approved PO-2026-0092?', 'payout_lookup'],
    ['Has the payout to Ivan been released?', null],
    ["Is the change of PixelForge's bank details verified?", 'bank_changes'],
    ['How do we verify a payment request?', null],
  ],
};

const REFUSALS = new Set<IntentName>(['refuse_mutation', 'refuse_secret', 'refuse_advice', 'refuse_other_workspace']);

describe('PayFlow intent table', () => {
  for (const [intent, phrasings] of Object.entries(POSITIVE)) {
    for (const locale of ['ru', 'en'] as const) {
      it(`routes ${locale} phrasings to ${intent}`, () => {
        expect(phrasings[locale].length).toBeGreaterThanOrEqual(3);
        for (const question of phrasings[locale]) {
          expect(matchIntent(question)?.rule.name, question).toBe(intent);
        }
      });
    }
  }

  for (const locale of ['ru', 'en'] as const) {
    it(`never refuses the ${locale} questions about payments`, () => {
      expect(NEGATIVE[locale].length).toBeGreaterThanOrEqual(5);
      for (const [question, expected] of NEGATIVE[locale]) {
        const match = matchIntent(question);
        expect(match?.rule.refusal, question).toBeUndefined();
        if (match) expect(REFUSALS.has(match.rule.name), question).toBe(false);
        expect(match?.rule.name ?? null, question).toBe(expected);
      }
    });
  }

  it('builds the tool steps of the headline questions', () => {
    expect(matchIntent('Какой штраф предусмотрен договором с Балтийской логистикой?')?.steps).toEqual([
      { tool: 'get_contract_terms', args: { client_query: 'Балтийской логистикой' } },
      { tool: 'search_documents', args: { query: 'late payment penalty interest Балтийской логистикой' } },
    ]);
    expect(matchIntent('Which invoice is the most overdue?')?.steps).toEqual([
      { tool: 'list_overdue_invoices', args: { sort: 'days_overdue', limit: 5 } },
    ]);
    expect(matchIntent('Какие реквизиты у поставщика PixelForge были изменены?')?.steps).toEqual([
      { tool: 'list_bank_detail_changes', args: { vendor_query: 'PixelForge', days: 90 } },
      { tool: 'search_documents', args: { query: 'bank details change verification callback procedure' } },
    ]);
    expect(matchIntent('Show me ns-2026-0131')?.steps).toEqual([{ tool: 'get_invoice', args: { number: 'NS-2026-0131' } }]);
  });

  it('resolves the client of a follow-up question from earlier user turns', () => {
    const history = ['Расскажи о Helios Media'];
    expect(matchIntent(history[0]!)?.rule.name).toBe('client_profile');
    const followUp = matchIntent('Когда напомнить этому клиенту?', history);
    expect(followUp?.rule.name).toBe('when_remind');
    expect(followUp?.steps[0]).toEqual({ tool: 'get_reminder_schedule', args: { client_query: 'Helios Media' } });
    expect(followUp?.missingEntity).toBe(false);
  });

  it('asks for a client when the reminder question has no entity and no history', () => {
    const match = matchIntent('Когда напомнить этому клиенту?');
    expect(match?.rule.name).toBe('when_remind');
    expect(match?.missingEntity).toBe(true);
    expect(match?.steps.map((step) => step.tool)).toEqual(['search_documents']);
  });
});

describe('entity extraction', () => {
  it('normalizes case, ё and spaces', () => {
    expect(normalizeQuestion('  Счёт   ПРОСРОЧЕН  ')).toBe('счет просрочен');
  });

  it('finds invoice numbers, quoted names and names after keywords', () => {
    expect(extractInvoiceNumber('что с ns-2026-0118?')).toBe('NS-2026-0118');
    expect(extractEntity('Расскажи про «Студия Альба»')).toBe('Студия Альба');
    expect(extractEntity('Tell me about "Kraft & Hale Architects" please')).toBe('Kraft & Hale Architects');
    expect(extractEntity('How does client Kraft & Hale pay?')).toBe('Kraft & Hale');
    expect(extractEntity('Когда напомнить клиенту Helios Media?')).toBe('Helios Media');
    expect(extractEntity('Какой штраф по договору с Балтийской логистикой?')).toBe('Балтийской логистикой');
    expect(extractEntity('Какие реквизиты у поставщика PixelForge были изменены?')).toBe('PixelForge');
    expect(extractEntity('Which invoice is the most overdue?')).toBeNull();
  });

  it('uses anaphora only when the question names nobody', () => {
    expect(resolveEntities('When should we remind them?', ['Tell me about Orbit Fintech'])).toEqual({ invoice: null, entity: 'Orbit Fintech', fromHistory: true });
    expect(resolveEntities('Он оплатил счёт?', ['Что со счётом NS-2026-0118?'])).toEqual({ invoice: 'NS-2026-0118', entity: null, fromHistory: true });
    expect(resolveEntities('Tell me about Helios Media', ['Tell me about Orbit Fintech']).entity).toBe('Helios Media');
    expect(resolveEntities('What is our DSO?', ['Tell me about Orbit Fintech']).entity).toBeNull();
  });
});
