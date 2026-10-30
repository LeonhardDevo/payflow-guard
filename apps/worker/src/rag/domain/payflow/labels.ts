export type Lang = 'ru' | 'en';

type Table = Record<string, Record<Lang, string>>;

export const TONES: Table = {
  friendly: { en: 'friendly reminder', ru: 'дружелюбное напоминание' },
  neutral: { en: 'neutral reminder', ru: 'нейтральное напоминание' },
  firm: { en: 'firm reminder', ru: 'настойчивое напоминание' },
  final: { en: 'final reminder', ru: 'последнее напоминание' },
};

export const RISK_BANDS: Table = {
  low: { en: 'low risk', ru: 'низкий риск' },
  medium: { en: 'medium risk', ru: 'средний риск' },
  high: { en: 'high risk', ru: 'высокий риск' },
};

export const CLIENT_CLASSES: Table = {
  new: { en: 'new client, no payment history yet', ru: 'новый клиент, истории оплат пока нет' },
  reliable: { en: 'reliable payer', ru: 'надёжный плательщик' },
  occasional: { en: 'occasionally pays late', ru: 'иногда задерживает оплату' },
  chronic: { en: 'systematically pays late', ru: 'систематически задерживает оплату' },
};

export const INVOICE_STATUSES: Table = {
  draft: { en: 'draft', ru: 'черновик' },
  open: { en: 'open', ru: 'ожидает оплаты' },
  due_soon: { en: 'due soon', ru: 'скоро срок оплаты' },
  overdue: { en: 'overdue', ru: 'просрочен' },
  partially_paid: { en: 'partially paid', ru: 'оплачен частично' },
  paid: { en: 'paid', ru: 'оплачен' },
  disputed: { en: 'disputed', ru: 'оспаривается' },
  uncollectible: { en: 'written off', ru: 'списан как безнадёжный' },
  void: { en: 'void', ru: 'аннулирован' },
};

export const BANK_STATUSES: Table = {
  pending_verification: { en: 'pending verification', ru: 'ожидает проверки' },
  verified: { en: 'verified', ru: 'подтверждено' },
  rejected: { en: 'rejected', ru: 'отклонено' },
  superseded: { en: 'replaced', ru: 'заменено' },
};

export const CHANGE_SOURCES: Table = {
  onboarding: { en: 'onboarding', ru: 'подключение поставщика' },
  vendor_email: { en: 'vendor e-mail', ru: 'письмо поставщика' },
  vendor_phone: { en: 'vendor phone call', ru: 'звонок поставщика' },
  vendor_portal: { en: 'vendor portal', ru: 'портал поставщика' },
  invoice_document: { en: 'invoice document', ru: 'документ счёта' },
  manual_entry: { en: 'manual entry', ru: 'ручной ввод' },
  import: { en: 'import', ru: 'импорт' },
};

export const SIGNALS: Table = {
  request_via_email: { en: 'requested by e-mail', ru: 'запрос пришёл по почте' },
  request_via_document: { en: 'requested through an invoice document', ru: 'запрос пришёл в документе счёта' },
  lookalike_domain: { en: 'lookalike sender domain', ru: 'домен отправителя похож на домен поставщика' },
  domain_mismatch: { en: 'sender domain differs from the vendor', ru: 'домен отправителя не совпадает с доменом поставщика' },
  holder_name_mismatch: { en: 'holder name does not match the vendor', ru: 'получатель не совпадает с поставщиком' },
  country_changed: { en: 'bank country changed', ru: 'сменилась страна банка' },
  currency_changed: { en: 'currency changed', ru: 'сменилась валюта' },
  shared_account: { en: 'account used by another vendor', ru: 'счёт используется другим поставщиком' },
  reused_rejected_account: { en: 'previously rejected account', ru: 'ранее отклонённый счёт' },
  pending_payout: { en: 'payout due soon', ru: 'скоро выплата' },
  recent_change: { en: 'another recent change', ru: 'недавно уже было изменение' },
  urgency_language: { en: 'urgent wording', ru: 'срочность в тексте запроса' },
  secrecy_language: { en: 'asks to avoid calls or checks', ru: 'просьба не звонить и не проверять' },
  new_contact_in_request: { en: 'new contact number in the request', ru: 'новый номер телефона в запросе' },
  new_vendor: { en: 'new vendor', ru: 'новый поставщик' },
  phone_recently_changed: { en: 'vendor phone recently changed', ru: 'телефон поставщика недавно изменён' },
};

export const AGING: Table = {
  current: { en: 'not yet due', ru: 'срок не наступил' },
  d1_30: { en: '1–30 days', ru: '1–30 дней' },
  d31_60: { en: '31–60 days', ru: '31–60 дней' },
  d61_90: { en: '61–90 days', ru: '61–90 дней' },
  d90_plus: { en: 'over 90 days', ru: 'более 90 дней' },
};

export function label(table: Table, code: unknown, lang: Lang): string {
  const key = typeof code === 'string' ? code : '';
  return table[key]?.[lang] ?? key.replace(/_/g, ' ');
}

const ruPlural = new Intl.PluralRules('ru');
const enPlural = new Intl.PluralRules('en');

export function days(count: number, lang: Lang): string {
  if (lang === 'ru') {
    const form = ruPlural.select(count);
    return `${count} ${form === 'one' ? 'день' : form === 'few' ? 'дня' : 'дней'}`;
  }
  return `${count} ${enPlural.select(count) === 'one' ? 'day' : 'days'}`;
}

export function invoices(count: number, lang: Lang): string {
  if (lang === 'ru') {
    const form = ruPlural.select(count);
    return `${count} ${form === 'one' ? 'счёт' : form === 'few' ? 'счёта' : 'счетов'}`;
  }
  return `${count} ${enPlural.select(count) === 'one' ? 'invoice' : 'invoices'}`;
}

export function approvals(count: number, lang: Lang): string {
  if (lang === 'ru') {
    const form = ruPlural.select(count);
    return `${count} ${form === 'one' ? 'согласование' : form === 'few' ? 'согласования' : 'согласований'}`;
  }
  return `${count} ${enPlural.select(count) === 'one' ? 'approval' : 'approvals'}`;
}
