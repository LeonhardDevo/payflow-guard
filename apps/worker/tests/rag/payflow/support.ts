import { readFileSync } from 'node:fs';
import { FakeWeb } from '../support/harness.js';

export const SEED_DIR = new URL('../../../../../docs/seed-documents/', import.meta.url);

export interface SeedDocument {
  file: string;
  title: string;
  locale: string;
  department: string | null;
  roles: string[];
  tags: string[];
  version: string | null;
  effective_date: string | null;
}

export function seedDocuments(): Array<SeedDocument & { id: number; content: string }> {
  const manifest = JSON.parse(readFileSync(new URL('manifest.json', SEED_DIR), 'utf8')) as { documents: SeedDocument[] };
  return manifest.documents.map((document, index) => ({
    ...document,
    id: 500 + index,
    content: readFileSync(new URL(document.file, SEED_DIR), 'utf8'),
  }));
}

const AS_OF = '2026-10-23T09:00:00Z';
const money = (minor: number, currency: string, formatted: string) => ({ minor, currency, formatted });

export const NEXT_REMINDER = { ru: '24 окт. 2026 г., 09:17', en: '24 Oct 2026, 09:17' };

type Lang = 'ru' | 'en';

function overdue(lang: Lang) {
  const first = lang === 'ru' ? '12 400,00 $' : '$12,400.00';
  const second = lang === 'ru' ? '3 000,00 $' : '$3,000.00';
  return {
    data: {
      items: [
        { number: 'NS-2026-0118', client: 'Helios Media Group', days_overdue: 52, balance_due: money(1240000, 'USD', first), due_date: { iso: '2026-09-01', formatted: '1 Sep 2026' }, risk_band: 'high', reminders_sent: 6, next_reminder_at: null, url: 'http://app.test/invoices/118' },
        { number: 'NS-2026-0131', client: 'Helios Media Group', days_overdue: 31, balance_due: money(300000, 'USD', second), due_date: { iso: '2026-09-22', formatted: '22 Sep 2026' }, risk_band: 'medium', reminders_sent: 5, next_reminder_at: null, url: 'http://app.test/invoices/131' },
      ],
      totals: [money(1540000, 'USD', lang === 'ru' ? '15 400,00 $' : '$15,400.00')],
      count: 2,
    },
    summary:
      lang === 'ru'
        ? 'Сильнее всего просрочен счёт NS-2026-0118 (Helios Media Group): 52 дня, к оплате 12 400,00 $.'
        : 'The most overdue invoice is NS-2026-0118 (Helios Media Group): 52 days, $12,400.00 due.',
    as_of: AS_OF,
  };
}

function schedule(lang: Lang) {
  return {
    data: {
      items: [
        {
          invoice_number: 'NS-2026-0118',
          client: 'Helios Media Group',
          next_reminder_at: { iso: '2026-10-24T06:17:00Z', formatted: NEXT_REMINDER[lang] },
          tone: 'final',
          tone_reasons: ['days_overdue', 'client_chronic'],
          step_offset_days: 45,
          policy_name: lang === 'ru' ? 'Стандартный график' : 'Standard cadence',
          paused: false,
          pause_reason: null,
          blocked_reason: null,
        },
      ],
      recommendation: {
        code: 'call',
        text:
          lang === 'ru'
            ? 'Клиент систематически платит с опозданием — по политике стоит позвонить в бухгалтерию клиента до последнего напоминания.'
            : 'The client systematically pays late — per the policy, call the client’s accounts team before the final reminder.',
      },
    },
    summary:
      lang === 'ru'
        ? `Следующее напоминание по счёту NS-2026-0118 (Helios Media Group): ${NEXT_REMINDER.ru}, тон — последнее напоминание. Рекомендация: позвонить клиенту.`
        : `Next reminder for NS-2026-0118 (Helios Media Group): ${NEXT_REMINDER.en}, tone — final reminder. Recommendation: call the client.`,
    resolved: { kind: 'client', id: 1, name: 'Helios Media Group', score: 1 },
    as_of: AS_OF,
  };
}

function profile(lang: Lang) {
  return {
    data: {
      client: {
        name: 'Helios Media Group',
        class: 'chronic',
        avg_days_late: 18.38,
        on_time_rate: 0,
        paid_invoices_count: 8,
        open_balance: money(1540000, 'USD', lang === 'ru' ? '15 400,00 $' : '$15,400.00'),
        overdue_balance: money(1540000, 'USD', lang === 'ru' ? '15 400,00 $' : '$15,400.00'),
        payment_terms_days: 30,
        owner: 'Laura Chen',
        tone_ceiling: 'none',
        reminders_paused: false,
        opted_out: false,
      },
      open_invoices: [{ number: 'NS-2026-0118', balance_due: money(1240000, 'USD', lang === 'ru' ? '12 400,00 $' : '$12,400.00'), days_overdue: 52 }],
      last_payments: [],
    },
    summary: lang === 'ru' ? 'Helios Media Group систематически платит с опозданием.' : 'Helios Media Group systematically pays late.',
    resolved: { kind: 'client', id: 1, name: 'Helios Media Group', score: 1 },
    as_of: AS_OF,
  };
}

function terms(lang: Lang) {
  return {
    data: {
      client: 'Балтийская логистика',
      payment_terms_days: 14,
      late_fee: { type: 'percent_daily', rate_percent: 0.1, fixed: null, grace_days: 0, cap_percent: 10, source: 'client' },
      contract_document: { id: 504, title: 'Договор № NSD-2025-031 — Балтийская логистика' },
      accrued_now: [{ invoice_number: 'NS-2026-0145', amount: money(2695, 'EUR', lang === 'ru' ? '26,95 €' : '€26.95'), chargeable_days: 7 }],
    },
    summary:
      lang === 'ru'
        ? 'Неустойка для «Балтийской логистики»: 0,1% в день, не более 10%. Начислено по NS-2026-0145: 26,95 €.'
        : 'Late fee for Baltic Logistics: 0.1% per day, capped at 10%. Accrued on NS-2026-0145: €26.95.',
    resolved: { kind: 'client', id: 5, name: 'Балтийская логистика', score: 0.62 },
    as_of: AS_OF,
  };
}

function bankChanges(lang: Lang) {
  return {
    data: {
      items: [
        {
          vendor: 'PixelForge Studio',
          version: 2,
          status: 'pending_verification',
          requested_at: { iso: '2026-10-23T05:12:00Z', formatted: lang === 'ru' ? '23 окт. 2026 г., 08:12' : '23 Oct 2026, 08:12' },
          requested_by: 'Mark Ellis',
          source: 'vendor_email',
          risk_score: 100,
          risk_band: 'high',
          top_signals: ['lookalike_domain', 'holder_name_mismatch', 'country_changed', 'request_via_email'],
          before: { bank_country: 'PL', currency: 'EUR', last4: '2874', holder_matches_vendor: true, holder_similarity: 'match' },
          after: { bank_country: 'LT', currency: 'EUR', last4: '0519', holder_matches_vendor: false, holder_similarity: 'mismatch' },
          verified_by: null,
          verified_at: null,
          verification_mode: null,
          cooling_off_until: null,
          affected_payouts: ['PO-2026-0087'],
        },
      ],
    },
    summary:
      lang === 'ru'
        ? 'PixelForge Studio: изменение реквизитов ожидает проверки, новый счёт LT •••• 0519, высокий риск (100/100). Выплата PO-2026-0087 приостановлена. Проверьте звонком по известному номеру.'
        : 'PixelForge Studio: bank details change pending verification, new account LT •••• 0519, high risk (100/100). Payout PO-2026-0087 is on hold. Verify by calling the known number.',
    resolved: { kind: 'vendor', id: 1, name: 'PixelForge Studio', score: 1 },
    as_of: AS_OF,
  };
}

const ROUTES: Record<string, (lang: Lang) => unknown> = {
  '/internal/v1/payflow/ai/invoices/overdue': overdue,
  '/internal/v1/payflow/ai/reminders/next': schedule,
  '/internal/v1/payflow/ai/clients/profile': profile,
  '/internal/v1/payflow/ai/clients/terms': terms,
  '/internal/v1/payflow/ai/vendors/bank-changes': bankChanges,
};

export class SeedWeb extends FakeWeb {
  readonly toolCalls: Array<{ path: string; query: Record<string, unknown> | undefined }> = [];

  override async getJson<T>(path?: string, query?: Record<string, unknown>): Promise<T> {
    this.toolCalls.push({ path: path ?? '', query });
    const route = ROUTES[path ?? ''];
    const lang: Lang = query?.locale === 'ru' ? 'ru' : 'en';
    if (!route) return { error: 'not_found', summary: lang === 'ru' ? 'Ничего не найдено.' : 'Nothing found.' } as T;
    return route(lang) as T;
  }
}
