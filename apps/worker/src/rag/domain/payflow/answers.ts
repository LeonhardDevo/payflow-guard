import type { ParsedSource } from '../../agent/sources.js';
import type { RenderInput, ToolOutcome } from '../../providers/mock.js';
import { splitSentences } from '../../text.js';
import type { IntentName } from './intents.js';
import {
  AGING,
  approvals,
  BANK_STATUSES,
  CHANGE_SOURCES,
  CLIENT_CLASSES,
  days,
  INVOICE_STATUSES,
  invoices,
  label,
  RISK_BANDS,
  SIGNALS,
  TONES,
  type Lang,
} from './labels.js';

type Obj = Record<string, unknown>;

interface Envelope {
  summary: string;
  data: Obj | null;
  as_of: string | null;
  error: 'not_found' | 'ambiguous' | 'forbidden' | null;
  candidates: Array<{ id: number; name: string }>;
}

export function parseEnvelope(content: string): Envelope | null {
  try {
    const value = JSON.parse(content) as Obj;
    if (!value || typeof value !== 'object') return null;
    const error = value.error;
    return {
      summary: typeof value.summary === 'string' ? value.summary : '',
      data: isObj(value.data) ? value.data : null,
      as_of: typeof value.as_of === 'string' ? value.as_of : null,
      error: error === 'not_found' || error === 'ambiguous' || error === 'forbidden' ? error : null,
      candidates: Array.isArray(value.candidates)
        ? value.candidates.filter(isObj).map((candidate) => ({ id: Number(candidate.id), name: str(candidate.name) }))
        : [],
    };
  } catch {
    return null;
  }
}

function isObj(value: unknown): value is Obj {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function str(value: unknown): string {
  if (typeof value === 'string') return value;
  if (typeof value === 'number') return String(value);
  if (isObj(value)) {
    if (typeof value.formatted === 'string') return value.formatted;
    if (typeof value.name === 'string') return value.name;
  }
  return '';
}

function num(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

function list(value: unknown): Obj[] {
  return Array.isArray(value) ? value.filter(isObj) : [];
}

function percent(value: unknown, lang: Lang): string {
  if (typeof value === 'string') return value.includes('%') ? value : `${value}%`;
  const number = num(value);
  if (number === null) return '';
  return `${new Intl.NumberFormat(lang === 'ru' ? 'ru-RU' : 'en-US', { maximumFractionDigits: 4 }).format(number)}%`;
}

const T = (lang: Lang, en: string, ru: string): string => (lang === 'ru' ? ru : en);

export function quote(sources: readonly ParsedSource[], keywords: readonly string[], prefer: (title: string) => boolean = () => false): string | null {
  const ordered = [...sources.filter((source) => prefer(source.title)), ...sources.filter((source) => !prefer(source.title))];
  for (const source of ordered) {
    for (const sentence of splitSentences(source.content)) {
      const lower = sentence.toLowerCase().replace(/ё/g, 'е');
      if (sentence.length >= 20 && keywords.some((keyword) => lower.includes(keyword))) {
        return `${sentence.trim().replace(/^[-*#>\s]+/, '')} [${source.ref}]`;
      }
    }
  }
  return null;
}

function asOfLine(envelopes: readonly Envelope[], lang: Lang): string {
  const stamp = envelopes.map((envelope) => envelope.as_of).find((value) => value !== null);
  if (!stamp || !Number.isFinite(Date.parse(stamp))) return '';
  const formatted = new Intl.DateTimeFormat(lang === 'ru' ? 'ru-RU' : 'en-GB', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'UTC',
  }).format(new Date(stamp));
  return `_${T(lang, 'Live PayFlow data as of', 'Актуальные данные PayFlow на')} ${formatted} UTC._`;
}

function failureText(envelope: Envelope, lang: Lang): string {
  if (envelope.error === 'ambiguous' && envelope.candidates.length > 0) {
    const names = envelope.candidates.slice(0, 3).map((candidate) => candidate.name).join(T(lang, ', or ', ', или '));
    return T(lang, `Which one do you mean: ${names}?`, `Кого именно вы имеете в виду: ${names}?`);
  }
  const summary = envelope.summary.trim();
  if (envelope.error === 'not_found') {
    return `${summary || T(lang, 'Nothing matching was found.', 'Ничего подходящего не найдено.')} ${T(lang, 'Please check the name or the number.', 'Проверьте название или номер.')}`;
  }
  if (envelope.error === 'forbidden') {
    return T(lang, 'You do not have access to this data. Ask an owner or an approver.', 'У вас нет доступа к этим данным. Обратитесь к владельцу или согласующему.');
  }
  return summary;
}

function renderOverdue(data: Obj, lang: Lang): string {
  const items = list(data.items);
  if (items.length === 0) return T(lang, 'There are no overdue invoices right now.', 'Сейчас просроченных счетов нет.');
  const [first, ...rest] = items as [Obj, ...Obj[]];
  const firstDays = num(first.days_overdue) ?? 0;
  const lines = [
    T(
      lang,
      `The most overdue invoice is **${str(first.number)}** (${str(first.client)}): ${days(firstDays, lang)} overdue, ${str(first.balance_due)} outstanding.`,
      `Сильнее всего просрочен счёт **${str(first.number)}** (${str(first.client)}): просрочка ${days(firstDays, lang)}, к оплате ${str(first.balance_due)}.`,
    ),
  ];
  if (rest.length > 0) {
    lines.push('', T(lang, 'Other overdue invoices:', 'Другие просроченные счета:'));
    for (const item of rest) lines.push(`- ${str(item.number)} — ${str(item.client)}, ${days(num(item.days_overdue) ?? 0, lang)}, ${str(item.balance_due)}`);
  }
  const totals = Array.isArray(data.totals) ? data.totals.map(str).filter((value) => value !== '') : [];
  const count = num(data.count);
  if (totals.length > 0) {
    const counted = count !== null ? ` (${invoices(count, lang)})` : '';
    lines.push('', `${T(lang, 'Total overdue', 'Всего просрочено')}: ${totals.join(' · ')}${counted}.`);
  }
  return lines.join('\n');
}

function renderInvoice(data: Obj, lang: Lang): string {
  const status = label(INVOICE_STATUSES, data.display_status ?? data.status, lang);
  const overdue = num(data.days_overdue);
  const lines = [
    T(
      lang,
      `Invoice **${str(data.number)}** (${str(data.client)}) is ${status}: ${str(data.balance_due)} outstanding of ${str(data.total)}, due ${str(data.due_date)}${overdue && overdue > 0 ? `, ${days(overdue, lang)} overdue` : ''}.`,
      `Счёт **${str(data.number)}** (${str(data.client)}): ${status}, к оплате ${str(data.balance_due)} из ${str(data.total)}, срок оплаты ${str(data.due_date)}${overdue && overdue > 0 ? `, просрочка ${days(overdue, lang)}` : ''}.`,
    ),
  ];
  const payments = list(data.payments);
  if (payments.length > 0) {
    lines.push(
      `${T(lang, 'Payments', 'Оплаты')}: ${payments.map((payment) => `${str(payment.amount)} (${str(payment.paid_at)})`).join('; ')}.`,
    );
  } else {
    lines.push(T(lang, 'No payments have been recorded yet.', 'Оплат пока не зарегистрировано.'));
  }
  const next = isObj(data.next_reminder) ? data.next_reminder : null;
  if (next) lines.push(`${T(lang, 'Next reminder', 'Следующее напоминание')}: ${str(next.at)} (${label(TONES, next.tone, lang)}).`);
  if (data.late_fee_suggestion) lines.push(`${T(lang, 'Suggested late fee', 'Рекомендуемая неустойка')}: ${str(data.late_fee_suggestion)}.`);
  return lines.join('\n');
}

function renderClient(data: Obj, lang: Lang): string {
  const client = isObj(data.client) ? data.client : data;
  const lines: string[] = [];
  const avg = num(client.avg_days_late);
  const onTime = num(client.on_time_rate);
  const behaviour = [label(CLIENT_CLASSES, client.class, lang)];
  if (avg !== null) behaviour.push(T(lang, `on average ${avg} days late`, `в среднем опаздывает на ${avg} дн.`));
  if (onTime !== null) behaviour.push(T(lang, `${Math.round(onTime * (onTime <= 1 ? 100 : 1))}% paid on time`, `${Math.round(onTime * (onTime <= 1 ? 100 : 1))}% оплат в срок`));
  lines.push(`**${str(client.name)}**: ${behaviour.join(', ')}.`);
  lines.push(
    T(
      lang,
      `Open balance ${str(client.open_balance) || '—'}, overdue ${str(client.overdue_balance) || '—'}; payment terms ${str(client.payment_terms_days)} days.`,
      `Открытый долг ${str(client.open_balance) || '—'}, просрочено ${str(client.overdue_balance) || '—'}; условия оплаты — ${str(client.payment_terms_days)} дн.`,
    ),
  );
  if (client.opted_out === true) lines.push(T(lang, 'The client has opted out of automated reminders.', 'Клиент отказался от автоматических напоминаний.'));
  else if (client.reminders_paused === true) lines.push(T(lang, 'Automated reminders are paused for this client.', 'Автоматические напоминания для клиента приостановлены.'));
  const open = list(data.open_invoices);
  if (open.length > 0) {
    lines.push('', T(lang, 'Open invoices:', 'Открытые счета:'));
    for (const invoice of open) {
      const overdue = num(invoice.days_overdue);
      lines.push(`- ${str(invoice.number)} — ${str(invoice.balance_due)}${overdue && overdue > 0 ? `, ${T(lang, 'overdue', 'просрочка')} ${days(overdue, lang)}` : ''}`);
    }
  }
  return lines.join('\n');
}

function renderSchedule(data: Obj, lang: Lang, sources: readonly ParsedSource[]): string {
  const items = list(data.items);
  const lines: string[] = [];
  if (items.length === 0) lines.push(T(lang, 'No reminders are scheduled.', 'Запланированных напоминаний нет.'));
  for (const item of items.slice(0, 3)) {
    if (item.paused === true || item.blocked_reason) {
      const reason = str(item.pause_reason) || str(item.blocked_reason);
      lines.push(
        T(
          lang,
          `Reminders for invoice ${str(item.invoice_number)} (${str(item.client)}) are on hold${reason ? `: ${reason}` : ''}.`,
          `Напоминания по счёту ${str(item.invoice_number)} (${str(item.client)}) приостановлены${reason ? `: ${reason}` : ''}.`,
        ),
      );
      continue;
    }
    lines.push(
      T(
        lang,
        `The next reminder for **${str(item.client)}** is scheduled for **${str(item.next_reminder_at)}**: invoice ${str(item.invoice_number)}, tone — ${label(TONES, item.tone, lang)}.`,
        `Следующее напоминание для **${str(item.client)}** запланировано на **${str(item.next_reminder_at)}**: счёт ${str(item.invoice_number)}, тон — ${label(TONES, item.tone, lang)}.`,
      ),
    );
  }
  const recommendation = isObj(data.recommendation) ? data.recommendation : null;
  if (recommendation && str(recommendation.text) !== '') lines.push(str(recommendation.text));
  const code = recommendation ? str(recommendation.code) : '';
  const finalTone = items.some((item) => item.tone === 'final');
  if (code === 'call' || code === 'escalate' || finalTone) {
    const cited = quote(sources, ['call', 'phone', 'позвон', 'звон', 'телефон'], (title) => /polic|политик/i.test(title));
    if (cited) lines.push(cited);
  }
  return lines.join('\n');
}

function renderTerms(data: Obj | null, lang: Lang, sources: readonly ParsedSource[]): string {
  const lines: string[] = [];
  if (data) {
    const fee = isObj(data.late_fee) ? data.late_fee : {};
    const type = str(fee.type);
    const client = str(data.client);
    if (type === 'percent_daily' || type === 'percent_monthly') {
      const period = type === 'percent_daily' ? T(lang, 'per day', 'в день') : T(lang, 'per month', 'в месяц');
      const cap = fee.cap_percent !== null && fee.cap_percent !== undefined ? percent(fee.cap_percent, lang) : '';
      lines.push(
        T(
          lang,
          `The late-payment penalty for **${client}** is **${percent(fee.rate_percent, lang)} ${period}**${cap ? `, capped at **${cap}**` : ''}.`,
          `Неустойка за просрочку для **${client}** — **${percent(fee.rate_percent, lang)} ${period}**${cap ? `, но не более **${cap}**` : ''}.`,
        ),
      );
    } else if (type === 'fixed') {
      lines.push(T(lang, `The late-payment charge for **${client}** is a fixed **${str(fee.fixed)}**.`, `Неустойка для **${client}** — фиксированная сумма **${str(fee.fixed)}**.`));
    } else {
      lines.push(T(lang, `No late-payment penalty is configured for **${client}**.`, `Для **${client}** неустойка за просрочку не настроена.`));
    }
    const grace = num(fee.grace_days);
    if (grace !== null && grace > 0) lines.push(T(lang, `Grace period: ${days(grace, lang)}.`, `Льготный период: ${days(grace, lang)}.`));
    const accrued = list(data.accrued_now);
    for (const entry of accrued) {
      const chargeable = num(entry.chargeable_days);
      lines.push(
        T(
          lang,
          `Accrued now on ${str(entry.invoice_number)}: **${str(entry.amount)}**${chargeable !== null ? ` (${days(chargeable, lang)})` : ''}.`,
          `Начислено сейчас по счёту ${str(entry.invoice_number)}: **${str(entry.amount)}**${chargeable !== null ? ` (${days(chargeable, lang)})` : ''}.`,
        ),
      );
    }
  }
  const cited = quote(sources, ['неустойк', 'пен', 'penalt', 'late payment', 'late-payment', 'interest', 'процент'], contractPreference(data));
  if (cited) lines.push(cited);
  return lines.join('\n');
}

function accountLine(side: unknown, lang: Lang): string {
  if (!isObj(side)) return T(lang, 'none', 'нет');
  return `${str(side.bank_country)} •••• ${str(side.last4)}${side.currency ? ` (${str(side.currency)})` : ''}`;
}

function renderBankChanges(data: Obj, lang: Lang, sources: readonly ParsedSource[]): string {
  const items = list(data.items);
  if (items.length === 0) return T(lang, 'No bank-detail changes were requested in this period.', 'За этот период изменений реквизитов не было.');
  const lines: string[] = [];
  let pending = false;
  for (const item of items.slice(0, 3)) {
    const status = str(item.status);
    if (status === 'pending_verification') pending = true;
    const signals = Array.isArray(item.top_signals) ? item.top_signals.map((code) => label(SIGNALS, code, lang)).join(', ') : '';
    const after = isObj(item.after) ? item.after : {};
    lines.push(
      T(
        lang,
        `**${str(item.vendor)}** — ${label(BANK_STATUSES, status, lang)} change (version ${str(item.version)}), requested ${str(item.requested_at)} by ${str(item.requested_by)} via ${label(CHANGE_SOURCES, item.source, lang)}.`,
        `**${str(item.vendor)}** — изменение реквизитов (версия ${str(item.version)}): ${label(BANK_STATUSES, status, lang)}; запрошено ${str(item.requested_at)}, инициатор ${str(item.requested_by)}, источник — ${label(CHANGE_SOURCES, item.source, lang)}.`,
      ),
    );
    lines.push(
      T(
        lang,
        `- New account: ${accountLine(item.after, lang)}; previous: ${accountLine(item.before, lang)}.`,
        `- Новый счёт: ${accountLine(item.after, lang)}; прежний: ${accountLine(item.before, lang)}.`,
      ),
    );
    lines.push(
      T(
        lang,
        `- **${label(RISK_BANDS, item.risk_band, lang)}** (${str(item.risk_score)}/100)${signals ? `: ${signals}` : ''}.`,
        `- **${label(RISK_BANDS, item.risk_band, lang)}** (${str(item.risk_score)}/100)${signals ? `: ${signals}` : ''}.`,
      ),
    );
    if (after.holder_matches_vendor === false) {
      lines.push(T(lang, '- The new account holder does not match the vendor.', '- Владелец нового счёта не совпадает с поставщиком.'));
    } else if (after.holder_matches_vendor === true) {
      lines.push(T(lang, '- The new account holder matches the vendor.', '- Владелец нового счёта совпадает с поставщиком.'));
    }
    const affected = Array.isArray(item.affected_payouts) ? item.affected_payouts.map(str).filter((value) => value !== '') : [];
    if (affected.length > 0 && status === 'pending_verification') {
      lines.push(T(lang, `- Payouts on hold until verification: ${affected.join(', ')}.`, `- Выплаты приостановлены до проверки: ${affected.join(', ')}.`));
    }
    if (item.verified_by) {
      lines.push(T(lang, `- Verified by ${str(item.verified_by)} on ${str(item.verified_at)}.`, `- Подтверждено: ${str(item.verified_by)}, ${str(item.verified_at)}.`));
    }
  }
  if (pending) {
    lines.push(
      '',
      T(
        lang,
        'Verify the change by calling the vendor on the known phone number on file — never the number from the request.',
        'Проверьте изменение звонком поставщику по известному номеру из карточки — никогда не по номеру из запроса.',
      ),
    );
  }
  const cited = quote(sources, ['call', 'callback', 'phone', 'звон', 'телефон'], (title) => /security|безопасност/i.test(title));
  if (cited) lines.push(cited);
  return lines.join('\n');
}

function renderApprovals(data: Obj, lang: Lang, payoutNumber: string | null): string {
  const items = list(data.items);
  if (payoutNumber) {
    const item = items.find((entry) => str(entry.number).toUpperCase() === payoutNumber);
    if (!item) {
      return T(
        lang,
        `Payout ${payoutNumber} is not waiting for approval. Open it in the app to see its status.`,
        `Выплата ${payoutNumber} не ожидает согласования. Откройте её в приложении, чтобы увидеть статус.`,
      );
    }
    return T(
      lang,
      `Payout **${str(item.number)}** (${str(item.vendor)}, ${str(item.amount)}) is waiting for approval: ${str(item.approvals_count)} of ${approvals(num(item.required_approvals) ?? 1, lang)}, ${label(RISK_BANDS, item.risk_band, lang)}. Decisions are made in the app.`,
      `Выплата **${str(item.number)}** (${str(item.vendor)}, ${str(item.amount)}) ожидает согласования: ${str(item.approvals_count)} из ${approvals(num(item.required_approvals) ?? 1, lang)}, ${label(RISK_BANDS, item.risk_band, lang)}. Решение принимается в приложении.`,
    );
  }
  if (items.length === 0) return T(lang, 'No payouts are waiting for approval.', 'Выплат, ожидающих согласования, нет.');
  const lines = [T(lang, `Payouts waiting for approval (${items.length}):`, `Выплаты на согласовании (${items.length}):`)];
  for (const item of items) {
    const awaiting = item.awaiting_user === true ? T(lang, ' — waiting for you', ' — ждёт вашего решения') : '';
    lines.push(
      `- ${str(item.number)} — ${str(item.vendor)}, ${str(item.amount)}, ${label(RISK_BANDS, item.risk_band, lang)}, ${str(item.approvals_count)}/${str(item.required_approvals)}${awaiting}`,
    );
  }
  return lines.join('\n');
}

function renderSummary(data: Obj, lang: Lang): string {
  const count = num(data.overdue_count) ?? 0;
  const lines = [
    T(
      lang,
      `Outstanding receivables: **${str(data.outstanding)}**, of which **${str(data.overdue)}** is overdue (${invoices(count, lang)}).`,
      `Дебиторская задолженность: **${str(data.outstanding)}**, из них просрочено **${str(data.overdue)}** (${invoices(count, lang)}).`,
    ),
  ];
  if (data.dso !== undefined && data.dso !== null) lines.push(`DSO: ${str(data.dso)}.`);
  if (data.collected_this_month) lines.push(`${T(lang, 'Collected this month', 'Собрано в этом месяце')}: ${str(data.collected_this_month)}.`);
  if (data.at_risk) lines.push(`${T(lang, 'At risk', 'Под риском')}: ${str(data.at_risk)}.`);
  const aging = list(data.aging);
  if (aging.length > 0) {
    lines.push('', T(lang, 'Aging:', 'Старение долга:'));
    for (const row of aging) lines.push(`- ${label(AGING, row.bucket, lang)}: ${str(row.amount)} (${invoices(num(row.count) ?? 0, lang)})`);
  }
  return lines.join('\n');
}

function renderLatePayers(data: Obj, lang: Lang): string {
  const items = list(data.items);
  if (items.length === 0) return T(lang, 'No client systematically pays late.', 'Клиентов, которые систематически задерживают оплату, нет.');
  const lines = [T(lang, 'Clients who pay late most often:', 'Клиенты, которые чаще всего платят с опозданием:')];
  for (const item of items) {
    const avg = num(item.avg_days_late);
    lines.push(
      `- ${str(item.client)} — ${label(CLIENT_CLASSES, item.class, lang)}${avg !== null ? T(lang, `, on average ${avg} days late`, `, в среднем ${avg} дн. опоздания`) : ''}${item.overdue_balance ? T(lang, `, overdue ${str(item.overdue_balance)}`, `, просрочено ${str(item.overdue_balance)}`) : ''}`,
    );
  }
  return lines.join('\n');
}

function result(results: readonly ToolOutcome[], tool: string): Envelope | null {
  const outcome = results.find((entry) => entry.tool === tool);
  return outcome ? parseEnvelope(outcome.content) : null;
}

export function renderAnswer(input: RenderInput): string | null {
  const lang: Lang = input.locale;
  const intent = input.plan.intent as IntentName;
  const sources = input.sources.filter((source) => source.content.trim() !== '');
  const envelopes = input.results.map((entry) => parseEnvelope(entry.content)).filter((entry): entry is Envelope => entry !== null);
  const primaryTool = input.plan.steps.find((step) => step.tool !== 'search_documents')?.tool ?? null;
  const primary = primaryTool ? result(input.results, primaryTool) : null;
  if (primaryTool && !primary) return null;
  if (primary?.error) return failureText(primary, lang);
  const data = primary?.data ?? null;
  let body: string | null;
  switch (intent) {
    case 'most_overdue':
      body = data ? renderOverdue(data, lang) : null;
      break;
    case 'invoice_lookup':
      body = data ? renderInvoice(data, lang) : null;
      break;
    case 'client_profile':
    case 'payment_status':
      body = data ? renderClient(data, lang) : null;
      break;
    case 'when_remind':
      body = data ? renderSchedule(data, lang, sources) : null;
      break;
    case 'penalty':
      body = renderTerms(data, lang, sources);
      break;
    case 'bank_changes':
      body = data ? renderBankChanges(data, lang, sources) : null;
      break;
    case 'pending_approvals':
    case 'payout_lookup':
      body = data ? renderApprovals(data, lang, typeof input.plan.payoutNumber === 'string' ? input.plan.payoutNumber : null) : null;
      break;
    case 'ar_summary':
      body = data ? renderSummary(data, lang) : null;
      break;
    case 'late_payers':
      body = data ? renderLatePayers(data, lang) : null;
      break;
    default:
      body = null;
  }
  if (body === null || body.trim() === '') return null;
  const stamp = primary ? asOfLine(envelopes, lang) : '';
  return stamp ? `${body}\n\n${stamp}` : body;
}

function contractPreference(data: Obj | null): (title: string) => boolean {
  const documentTitle = data && isObj(data.contract_document) ? str(data.contract_document.title) : '';
  const stems = (data ? str(data.client) : '')
    .toLowerCase()
    .split(/[^\p{L}\d]+/u)
    .filter((word) => word.length >= 4)
    .map((word) => word.slice(0, 5));
  return (title) => {
    if (documentTitle !== '' && title === documentTitle) return true;
    const lower = title.toLowerCase();
    return stems.length > 0 && stems.some((stem) => lower.includes(stem));
  };
}
