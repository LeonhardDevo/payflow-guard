export const INVOICE_NUMBER_RE = /\b[a-zа-яё]{1,5}-\d{4}-\d{3,5}\b/iu;

const QUOTED_RE = /«([^»]{2,80})»|"([^"]{2,80})"|“([^”]{2,80})”/u;

const KEYWORDS = new Set([
  'клиент',
  'клиента',
  'клиенту',
  'клиенте',
  'клиентом',
  'поставщик',
  'поставщика',
  'поставщику',
  'поставщике',
  'поставщиком',
  'контрагент',
  'контрагента',
  'контрагенту',
  'контрагенте',
  'контрагентом',
  'client',
  'customer',
  'vendor',
  'supplier',
  'о',
  'об',
  'про',
  'с',
  'у',
  'для',
  'по',
  'about',
  'on',
  'for',
  'from',
  'with',
]);

const ANAPHORA_RE =
  /(?:^|[\s,.!?;:])(?:этому клиенту|этого клиента|этот клиент|этим клиентом|этом клиенте|ему|него|ним|нему|он|она|они|this client|that client|them|they)(?=$|[\s,.!?;:])/iu;

const CAPITALIZED_RE = /^[A-ZА-ЯЁ][\p{L}\d'’.-]*$/u;
const CONNECTOR_RE = /^(?:&|and|и)$/iu;
const RU_ADJECTIVE_RE = /[а-яё]{3,}(?:ая|ой|ий|ый|ого|ому|ым|ую|ей|ое|ие|ых|ыми|ой)$/u;
const RU_LOWER_RE = /^[а-яё][а-яё-]{2,}$/u;
const STOP_TOKENS = new Set(['PO', 'NS', 'IBAN', 'DSO', 'AR', 'I', 'Я']);
const MAX_ENTITY_TOKENS = 4;

export function normalizeQuestion(text: string): string {
  return text.toLowerCase().replace(/ё/g, 'е').replace(/\s+/g, ' ').trim();
}

export function extractInvoiceNumber(text: string): string | null {
  const match = INVOICE_NUMBER_RE.exec(text);
  return match ? match[0].toUpperCase() : null;
}

function cleanToken(token: string): string {
  return token.replace(/^[«"“(]+/u, '').replace(/[»"”),.!?;:]+$/u, '');
}

export function extractEntity(text: string): string | null {
  const quoted = QUOTED_RE.exec(text);
  if (quoted) {
    const value = (quoted[1] ?? quoted[2] ?? quoted[3] ?? '').trim();
    if (value !== '') return value;
  }
  const raw = text.split(/\s+/u).filter((token) => token !== '');
  for (let index = 0; index < raw.length - 1; index++) {
    const keyword = cleanToken(raw[index] ?? '').toLowerCase();
    if (!KEYWORDS.has(keyword)) continue;
    const entity = capitalizedRun(raw, index + 1);
    if (entity) return entity;
  }
  return null;
}

function capitalizedRun(tokens: readonly string[], start: number): string | null {
  const taken: string[] = [];
  for (let index = start; index < tokens.length && taken.length < MAX_ENTITY_TOKENS; index++) {
    const rawToken = tokens[index] ?? '';
    const token = cleanToken(rawToken);
    if (token === '') break;
    if (taken.length > 0 && CONNECTOR_RE.test(token) && CAPITALIZED_RE.test(cleanToken(tokens[index + 1] ?? ''))) {
      taken.push(token);
      continue;
    }
    if (!CAPITALIZED_RE.test(token) || STOP_TOKENS.has(token) || INVOICE_NUMBER_RE.test(token)) break;
    taken.push(token);
    if (rawToken !== token && /[,.!?;:)»"”]$/u.test(rawToken)) break;
  }
  if (taken.length === 0) return null;
  const last = taken[taken.length - 1] ?? '';
  const next = cleanToken(tokens[start + taken.length] ?? '');
  if (taken.length < MAX_ENTITY_TOKENS && RU_ADJECTIVE_RE.test(last.toLowerCase()) && RU_LOWER_RE.test(next)) taken.push(next);
  return taken.join(' ');
}

export function hasAnaphora(text: string): boolean {
  return ANAPHORA_RE.test(text);
}

export interface ResolvedEntities {
  invoice: string | null;
  entity: string | null;
  fromHistory: boolean;
}

export function resolveEntities(question: string, history: readonly string[]): ResolvedEntities {
  const invoice = extractInvoiceNumber(question);
  const entity = extractEntity(question);
  if (entity || invoice || !hasAnaphora(question)) return { invoice, entity, fromHistory: false };
  for (let index = history.length - 1; index >= 0; index--) {
    const turn = history[index] ?? '';
    const previous = extractEntity(turn);
    if (previous) return { invoice: null, entity: previous, fromHistory: true };
    const previousInvoice = extractInvoiceNumber(turn);
    if (previousInvoice) return { invoice: previousInvoice, entity: null, fromHistory: true };
  }
  return { invoice: null, entity: null, fromHistory: false };
}
