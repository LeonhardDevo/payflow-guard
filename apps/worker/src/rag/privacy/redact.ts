const WORD = String.raw`[\p{L}\p{N}_]`;
const NOT_AFTER_CODE = String.raw`(?<!\p{L}-)(?<![#№/])`;
const SLOT_OPEN = '';
const SLOT_CLOSE = '';

const REFERENCE_RE = new RegExp(String.raw`(?<![\p{L}\p{N}_-])(?:\p{L}{2,10}-|#|№ ?)\d+(?:-\d+)*(?![\p{L}\p{N}_-])`, 'gu');
const SLOT_RE = new RegExp(`${SLOT_OPEN}(\\d+)${SLOT_CLOSE}`, 'gu');
const EMAIL_RE = new RegExp(String.raw`(?<![\p{L}\p{N}_.+-])[\p{L}\p{N}_.+-]{1,64}@[\p{L}\p{N}_-]{1,63}(?:\.[\p{L}\p{N}_-]{1,63})+`, 'gu');
const IBAN_RE = new RegExp(String.raw`(?<!${WORD})[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]){11,30}(?!${WORD})`, 'gu');
const SNILS_RE = /(?<![\d-])\d{3}-\d{3}-\d{3}[ -]\d{2}(?![\d-])/gu;
const PASSPORT_RU_RE = /(?<![\d-])\d{2} ?\d{2} \d{6}(?![\d-])/gu;
const CARD_RE = new RegExp(String.raw`${NOT_AFTER_CODE}(?<![\d-])\d(?:[ -]?\d){12,18}(?![\d-])`, 'gu');
const CARD_GROUPED_RE = /^(?:\d{4}(?:[ -]\d{4}){2,3}(?:[ -]\d{1,3})?|\d{4}[ -]\d{6}[ -]\d{4,5})$/u;
const ACCOUNT_RE = new RegExp(String.raw`${NOT_AFTER_CODE}(?<!\d)\d{10,34}(?!\d)`, 'gu');
const PHONE_RE = new RegExp(String.raw`${NOT_AFTER_CODE}(?<![\p{L}\p{N}_+(])(?:\+|\()?\d[\d ().-]{6,20}\d(?!${WORD})`, 'gu');

function digitsOf(value: string): string {
  return value.replace(/\D/g, '');
}

export function luhnValid(number: string): boolean {
  let total = 0;
  for (let index = 0; index < number.length; index++) {
    let digit = Number(number[number.length - 1 - index]);
    if (index % 2 === 1) {
      digit *= 2;
      if (digit > 9) digit -= 9;
    }
    total += digit;
  }
  return total % 10 === 0;
}

function mask(kind: string, value: string): string {
  const tail = digitsOf(value).slice(-4);
  return tail ? `[${kind} ****${tail}]` : `[${kind}]`;
}

function card(value: string): string {
  const number = digitsOf(value);
  if (number.length < 13 || number.length > 19) return value;
  if (luhnValid(number)) return mask('card', number);
  if (CARD_GROUPED_RE.test(value)) return mask('number', number);
  return value;
}

function phone(value: string): string {
  const digits = digitsOf(value);
  if ((value.startsWith('+') && digits.length >= 8 && digits.length <= 15) || (digits.length >= 10 && digits.length <= 15)) return mask('phone', digits);
  return value;
}

export function redactText(text: string): string {
  if (text === '') return text;
  const references: string[] = [];
  let redacted = text.replace(EMAIL_RE, '[email]');
  redacted = redacted.replace(REFERENCE_RE, (match) => {
    references.push(match);
    return `${SLOT_OPEN}${references.length - 1}${SLOT_CLOSE}`;
  });
  redacted = redacted.replace(IBAN_RE, (match) => mask('IBAN', match));
  redacted = redacted.replace(SNILS_RE, '[SNILS]');
  redacted = redacted.replace(PASSPORT_RU_RE, (match) => mask('passport', match));
  redacted = redacted.replace(CARD_RE, card);
  redacted = redacted.replace(ACCOUNT_RE, (match) => mask('account', match));
  redacted = redacted.replace(PHONE_RE, phone);
  return redacted.replace(SLOT_RE, (_match, index: string) => references[Number(index)] ?? '');
}

export const redactPii = redactText;
