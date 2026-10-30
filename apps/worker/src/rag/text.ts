const WORD_RE = /[\p{L}\p{N}]+/gu;
const TOKEN_RE = /[\p{L}\p{N}_]+|[^\p{L}\p{N}_\s]/gu;
const SENTENCE_RE = /(?<=[.!?…])\s+(?=["'«([]?[A-ZА-ЯЁ0-9])|\n+/u;
const CYRILLIC_RE = /[а-яё]/i;
const ALNUM_START_RE = /^[\p{L}\p{N}_]/u;
const DIGITS_RE = /^\d+$/;

const STOPWORD_SOURCE = `
a an the and or but if of to in on at by for with from as is are was were be been being am do does did have
has had not no yes it its this that these those there here what which who whom whose when where why how i me
my we our you your he him his she her they them their can could should would will shall may might must about
into over under than then so such any all some each other more most very just also only own same too please
tell show give get let know need want find search и в во не что он на я с со как а то все она так его но да
ты к у же вы за бы по только ее её мне было вот от меня еще ещё нет о из ему теперь когда даже ну вдруг ли
если уже или ни быть был него до вас нибудь опять уж вам ведь там потом себя ничего ей может они тут где
есть надо ней для мы тебя их чем была сам чтоб без будто чего раз тоже себе под будет ж тогда кто этот того
потому этого какой совсем ним здесь этом один почти мой тем чтобы нее неё сейчас были куда зачем всех
никогда можно при наконец два об другой хоть после над больше тот через эти нас про всего них какая много
разве три эту моя впрочем хорошо свою этой перед иногда лучше чуть том нельзя такой им более всегда конечно
всю между это мне меня сколько скажи подскажи расскажи покажи найди какие каков какова каковы ли нужно хочу
`;

const RU_SUFFIXES = [
  'иями', 'ями', 'ами', 'ией', 'ого', 'его', 'ому', 'ему', 'ыми', 'ими', 'иях', 'ах', 'ях', 'ов', 'ев', 'ей', 'ой',
  'ий', 'ый', 'ая', 'яя', 'ое', 'ее', 'ие', 'ые', 'ом', 'ем', 'ам', 'ям', 'ую', 'юю', 'а', 'я', 'о', 'е', 'и', 'ы',
  'у', 'ю', 'ь', 'й',
];
const EN_SUFFIXES = ['ingly', 'edly', 'ing', 'ies', 'ied', 'ed', 'es', 'ly', 's'];

export function fold(text: string): string {
  const lowered = text.toLowerCase().replaceAll('ё', 'е').replaceAll('й', 'и');
  return lowered.normalize('NFKD').replace(/\p{M}/gu, '');
}

const FOLDED_STOPWORDS = new Set(STOPWORD_SOURCE.split(/\s+/).filter(Boolean).map(fold));

export function words(text: string): string[] {
  return fold(text).match(WORD_RE) ?? [];
}

export function stem(token: string): string {
  if (DIGITS_RE.test(token) || token.length <= 3) return token;
  const suffixes = CYRILLIC_RE.test(token) ? RU_SUFFIXES : EN_SUFFIXES;
  for (const suffix of suffixes) {
    if (token.endsWith(suffix) && token.length - suffix.length >= 3) return token.slice(0, -suffix.length);
  }
  return token;
}

export function contentTerms(text: string): string[] {
  const seen = new Set<string>();
  for (const token of words(text)) {
    if (FOLDED_STOPWORDS.has(token) || (token.length < 2 && !DIGITS_RE.test(token))) continue;
    seen.add(stem(token));
  }
  return [...seen];
}

export function queryLexemes(text: string, limit = 24): string[] {
  const seen = new Set<string>();
  for (const token of words(text)) {
    if (FOLDED_STOPWORDS.has(token) || token.length < 2) continue;
    seen.add(token);
    if (seen.size >= limit) break;
  }
  return [...seen];
}

export function coverage(queryTerms: readonly string[], text: string): number {
  if (queryTerms.length === 0) return 0;
  const present = new Set(contentTerms(text));
  return queryTerms.filter((term) => present.has(term)).length / queryTerms.length;
}

export function estimateTokens(text: string): number {
  const pieces = text.match(TOKEN_RE) ?? [];
  if (pieces.length === 0) return 0;
  const wordCount = pieces.filter((piece) => ALNUM_START_RE.test(piece)).length;
  return Math.max(1, Math.ceil(wordCount * 1.3 + (pieces.length - wordCount) * 0.5));
}

export function splitSentences(text: string): string[] {
  return text
    .split(SENTENCE_RE)
    .map((sentence) => sentence.trim())
    .filter((sentence) => sentence !== '');
}

export function truncate(text: string, limit: number): string {
  const clean = text.split(/\s+/).filter(Boolean).join(' ');
  if (clean.length <= limit) return clean;
  const cut = clean.slice(0, limit);
  const space = cut.lastIndexOf(' ');
  return `${space > 0 ? cut.slice(0, space) : cut}…`;
}

export function collapseWhitespace(text: string): string {
  return text.split(/\s+/).filter(Boolean).join(' ');
}
