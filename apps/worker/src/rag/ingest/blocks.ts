export type BlockKind = 'heading' | 'paragraph' | 'list' | 'table' | 'code';

export interface Block {
  text: string;
  kind: BlockKind;
  level: number;
  page: number | null;
}

export interface ParsedDocument {
  blocks: Block[];
  pages: number | null;
  ocrUsed: boolean;
  warnings: string[];
}

export function block(text: string, kind: BlockKind = 'paragraph', level = 0, page: number | null = null): Block {
  return { text, kind, level, page };
}

const NUMBERED_HEADING = /^(\d{1,2}(?:\.\d{1,2}){0,4})[.)]?\s+(\S.{0,110})$/u;
const KEYWORD_HEADING =
  /^(?:глава|раздел|часть|статья|приложение|chapter|section|part|article|appendix)\s+[\p{L}\p{N}_.\-IVXLC]+(?![\p{L}\p{N}_]).{0,100}$/iu;
const BULLET = /^\s*(?:[•·▪◦‣\-–—*]|\(?[a-zа-я]\)|\d{1,3}[.)])\s+/iu;
const SENTENCE_END = /[.!?…:;]$/u;
const LETTER = /\p{L}/u;

export function isUpper(char: string): boolean {
  return char !== '' && LETTER.test(char) && char === char.toUpperCase() && char !== char.toLowerCase();
}

export function isLower(char: string): boolean {
  return char !== '' && LETTER.test(char) && char === char.toLowerCase() && char !== char.toUpperCase();
}

function endsWithSeparator(text: string): boolean {
  return text.endsWith('.') || text.endsWith(';') || text.endsWith(',');
}

export function headingLevel(line: string): number {
  const stripped = line.trim();
  if (stripped === '' || stripped.length > 120) return 0;
  const numbered = NUMBERED_HEADING.exec(stripped);
  if (numbered && !endsWithSeparator(stripped)) {
    const title = numbered[2] ?? '';
    if (title.split(/\s+/).filter(Boolean).length <= 14 && isUpper(title.slice(0, 1))) {
      return Math.min(6, (numbered[1] ?? '').split('.').length);
    }
  }
  if (KEYWORD_HEADING.test(stripped) && !endsWithSeparator(stripped)) return 1;
  const letters = [...stripped].filter((char) => LETTER.test(char));
  if (
    letters.length >= 4 &&
    letters.filter(isUpper).length / letters.length > 0.85 &&
    stripped.split(/\s+/).filter(Boolean).length <= 10 &&
    !endsWithSeparator(stripped)
  ) {
    return 1;
  }
  return 0;
}

export function linesToBlocks(text: string, page: number | null = null): Block[] {
  const blocks: Block[] = [];
  let buffer: string[] = [];
  let bufferKind: BlockKind = 'paragraph';

  const flush = (): void => {
    if (buffer.length > 0) {
      const joined = buffer.join(' ').trim();
      if (joined !== '') blocks.push(block(joined, bufferKind, 0, page));
    }
    buffer = [];
    bufferKind = 'paragraph';
  };

  for (const rawLine of text.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n')) {
    const line = rawLine.split(/\s+/).filter(Boolean).join(' ');
    if (line === '') {
      flush();
      continue;
    }
    const level = headingLevel(line);
    if (level > 0) {
      flush();
      blocks.push(block(line, 'heading', level, page));
      continue;
    }
    if (BULLET.test(line)) {
      flush();
      buffer = [line];
      bufferKind = 'list';
      continue;
    }
    const last = buffer[buffer.length - 1];
    if (last !== undefined && last.endsWith('-') && isLower(line.slice(0, 1))) {
      buffer[buffer.length - 1] = last.slice(0, -1) + line;
      continue;
    }
    if (last !== undefined && SENTENCE_END.test(last) && (isUpper(line.slice(0, 1)) || /^\d/.test(line))) flush();
    buffer.push(line);
  }
  flush();
  return blocks;
}
