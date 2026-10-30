import type { PlannedStep } from '../../providers/mock.js';
import { extractInvoiceNumber, normalizeQuestion, resolveEntities, type ResolvedEntities } from './entities.js';

export type RefusalCode = 'mutation_not_allowed' | 'secret_requested' | 'advice_not_allowed' | 'other_workspace';

export type IntentName =
  | 'refuse_mutation'
  | 'refuse_secret'
  | 'refuse_advice'
  | 'refuse_other_workspace'
  | 'penalty'
  | 'when_remind'
  | 'bank_changes'
  | 'invoice_lookup'
  | 'payout_lookup'
  | 'most_overdue'
  | 'pending_approvals'
  | 'ar_summary'
  | 'late_payers'
  | 'client_profile'
  | 'payment_status';

export interface ParsedQuestion {
  original: string;
  normalized: string;
  entities: ResolvedEntities;
  payoutNumber: string | null;
}

export type ArgsBuilder = (q: ParsedQuestion) => Record<string, unknown> | null;

export interface IntentRule {
  name: IntentName;
  patterns: readonly RegExp[];
  tools?: ReadonlyArray<readonly [toolName: string, buildArgs: ArgsBuilder]>;
  refusal?: RefusalCode;
  requiresEntity?: boolean;
  when?: (q: ParsedQuestion) => boolean;
}

const END = '(?=$|[^\\p{L}\\d])';

function re(source: string): RegExp {
  return new RegExp(source, 'u');
}

export const MUTATION_PATTERNS: readonly RegExp[] = [
  re(`^(?:пожалуйста[,\\s]+)?(?:одобри|согласуй|подтверди|оплати|переведи|перечисли|отклони|измени|поменяй|проведи|отправь деньги)${END}`),
  re(`^(?:можешь|можете|могли бы вы)\\s+(?:одобрить|согласовать|оплатить|перевести|отклонить|изменить|провести)${END}`),
  re(`^(?:please[,\\s]+)?(?:approve|reject|pay|transfer|wire|release|change|update)${END}`),
  re(`^(?:can|could|would) you\\s+(?:please\\s+)?(?:approve|reject|pay|transfer|wire|release|change)${END}`),
];

export const SECRET_PATTERNS: readonly RegExp[] = [
  re('(?:полн\\p{L}*|целиком|весь)\\s+(?:iban|номер сч[её]та|реквизит)'),
  re('\\bfull\\s+(?:iban|account number|card number)\\b'),
  re('парол|password|api key|ключ api'),
];

export const ADVICE_PATTERNS: readonly RegExp[] = [
  re('(?:во что|куда)\\s+(?:лучше\\s+)?(?:инвестир|вложить)|инвестиционн\\p{L}* совет|какие акции'),
  re('\\b(?:investment advice|should i invest|which stocks|what to invest in)\\b'),
  re('(?:налогов\\p{L}*|юридическ\\p{L}*)\\s+(?:совет|консультац)|подать в суд'),
  re('\\b(?:tax advice|legal advice|should i sue|sue (?:them|the client))\\b'),
];

export const OTHER_WORKSPACE_PATTERNS: readonly RegExp[] = [
  re('(?:друг\\p{L}*|чуж\\p{L}*)\\s+(?:рабоч\\p{L}*\\s+)?(?:пространств|компани|организаци)'),
  re('\\b(?:other|another|someone else.s)\\s+(?:workspaces?|compan(?:y|ies)|organi[sz]ations?)\\b'),
];

const PENALTY = [
  re('штраф|пен[яиюе]|неустойк|процент\\p{L}* за просрочк|санкци'),
  re('late fee|penalt|late[- ]payment (?:charge|interest)|interest on (?:late|overdue)'),
];
const WHEN_REMIND = [
  re('(?:когда|в какой день|какого числа).*(?:напомн|написать|писать)|следующее напоминание'),
  re('when\\b.*\\bremind|next reminder|remind\\b.*\\bwhen'),
];
const BANK_CHANGES = [
  re('(?:реквизит|iban|банковск\\p{L}* данн|счет получател).*(?:измен|смен|помен|обнов|новы)|(?:измен|смен|помен)\\p{L}*.*реквизит'),
  re('(?:bank|payment|account|iban)\\s*(?:details?|info\\p{L}*|number)?.*(?:chang|updat|new)|(?:chang|updat)\\p{L}*.*(?:bank|iban|account) details'),
];
const MOST_OVERDUE = [
  re('просроч\\p{L}*.*(?:сильнее|больше всего|самый|наиболее|дольше)|(?:какой|какие|номер).*(?:сч[её]т\\p{L}*).*просроч|просроченн\\p{L}+ сч[её]т'),
  re('(?:most|longest|oldest)\\s+overdue|which invoices?.*overdue|overdue invoices?|late invoices?'),
];
const PENDING_APPROVALS = [
  re('(?:ожида|ждут|ждет|висят).*(?:одобрен|согласован|подтвержд)|на согласовании|что согласовать'),
  re('(?:pending|awaiting|waiting).*approv|approvals? (?:queue|pending)|what .* (?:to|should i) approve'),
];
const AR_SUMMARY = [
  re('дебитор|сколько (?:нам )?должны|задолженност|dso|старени\\p{L}* долг|оборачиваемост'),
  re('receivable|how much (?:are we|is) owed|outstanding (?:balance|amount)|aging|\\bdso\\b'),
];
const LATE_PAYERS = [
  re('(?:систематическ|постоянно|регулярно|часто|кто).*(?:поздно|опаздыва|задержива|просрочива)|злостн'),
  re('(?:who|which clients?).*(?:pays?|paying) late|late payers?|chronic(?:ally)? late|systematically late'),
];
const CLIENT_PROFILE = [
  re('расскажи|что (?:с|по) клиент|как платит|история клиента'),
  re('tell me about|how does .* pay|client (?:profile|history)|payment history'),
];

const PAYMENT_STATUS = [re('оплат(?:ил|ила|или|ит|ят)'), re('\\b(?:paid|pay)\\b')];

const PAYOUT_NUMBER_RE = /\bPO-\d{4}-\d{3,5}\b/i;

const clientArg = (q: ParsedQuestion): Record<string, unknown> | null => (q.entities.entity ? { client_query: q.entities.entity } : null);

export const PAYFLOW_INTENTS: readonly IntentRule[] = [
  { name: 'refuse_mutation', patterns: MUTATION_PATTERNS, refusal: 'mutation_not_allowed' },
  { name: 'refuse_secret', patterns: SECRET_PATTERNS, refusal: 'secret_requested' },
  { name: 'refuse_advice', patterns: ADVICE_PATTERNS, refusal: 'advice_not_allowed' },
  { name: 'refuse_other_workspace', patterns: OTHER_WORKSPACE_PATTERNS, refusal: 'other_workspace' },
  {
    name: 'penalty',
    patterns: PENALTY,
    tools: [
      ['get_contract_terms', clientArg],
      ['search_documents', (q) => ({ query: `late payment penalty interest ${q.entities.entity ?? ''}`.trim() })],
    ],
  },
  {
    name: 'when_remind',
    patterns: WHEN_REMIND,
    requiresEntity: true,
    tools: [
      [
        'get_reminder_schedule',
        (q) => (q.entities.invoice ? { invoice_number: q.entities.invoice } : q.entities.entity ? { client_query: q.entities.entity } : null),
      ],
      ['search_documents', () => ({ query: 'reminder cadence escalation call chronic' })],
    ],
  },
  {
    name: 'bank_changes',
    patterns: BANK_CHANGES,
    tools: [
      ['list_bank_detail_changes', (q) => ({ ...(q.entities.entity ? { vendor_query: q.entities.entity } : {}), days: 90 })],
      ['search_documents', () => ({ query: 'bank details change verification callback procedure' })],
    ],
  },
  {
    name: 'payout_lookup',
    patterns: [/./u],
    when: (q) => q.payoutNumber !== null,
    tools: [['list_pending_approvals', () => ({})]],
  },
  {
    name: 'invoice_lookup',
    patterns: [/./u],
    when: (q) => q.entities.invoice !== null,
    tools: [['get_invoice', (q) => (q.entities.invoice ? { number: q.entities.invoice } : null)]],
  },
  {
    name: 'most_overdue',
    patterns: MOST_OVERDUE,
    tools: [['list_overdue_invoices', (q) => ({ sort: 'days_overdue', limit: 5, ...(q.entities.entity ? { client_query: q.entities.entity } : {}) })]],
  },
  { name: 'pending_approvals', patterns: PENDING_APPROVALS, tools: [['list_pending_approvals', () => ({})]] },
  { name: 'ar_summary', patterns: AR_SUMMARY, tools: [['get_ar_summary', () => ({})]] },
  { name: 'late_payers', patterns: LATE_PAYERS, tools: [['list_late_payers', () => ({ limit: 5 })]] },
  {
    name: 'client_profile',
    patterns: CLIENT_PROFILE,
    when: (q) => q.entities.entity !== null,
    tools: [['get_client_profile', clientArg]],
  },
  {
    name: 'payment_status',
    patterns: PAYMENT_STATUS,
    when: (q) => q.entities.entity !== null,
    tools: [['get_client_profile', clientArg]],
  },
];

export const INTENT_RULES = PAYFLOW_INTENTS;

export function parseQuestion(question: string, history: readonly string[]): ParsedQuestion {
  const payoutNumber = PAYOUT_NUMBER_RE.exec(question)?.[0]?.toUpperCase() ?? null;
  const entities = resolveEntities(question, history);
  const invoice = entities.invoice && payoutNumber !== null && entities.invoice === payoutNumber ? null : entities.invoice;
  return {
    original: question,
    normalized: normalizeQuestion(question),
    entities: { ...entities, invoice: invoice && !PAYOUT_NUMBER_RE.test(invoice) ? invoice : null },
    payoutNumber,
  };
}

export interface IntentMatch {
  rule: IntentRule;
  question: ParsedQuestion;
  steps: PlannedStep[];
  missingEntity: boolean;
}

export function matchIntent(question: string, history: readonly string[] = []): IntentMatch | null {
  const parsed = parseQuestion(question, history);
  for (const rule of PAYFLOW_INTENTS) {
    if (!rule.patterns.some((pattern) => pattern.test(parsed.normalized))) continue;
    if (rule.when && !rule.when(parsed)) continue;
    if (rule.refusal) return { rule, question: parsed, steps: [], missingEntity: false };
    const steps: PlannedStep[] = [];
    let missingEntity = false;
    for (const [tool, build] of rule.tools ?? []) {
      const args = build(parsed);
      if (args === null) {
        if (rule.requiresEntity) missingEntity = true;
        continue;
      }
      steps.push({ tool, args });
    }
    return { rule, question: parsed, steps, missingEntity };
  }
  return null;
}

export function invoiceNumberIn(text: string): string | null {
  return extractInvoiceNumber(text);
}
