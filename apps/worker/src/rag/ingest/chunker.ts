import { estimateTokens, splitSentences } from '../text.js';
import type { Block } from './blocks.js';

export const SECTION_SEPARATOR = ' > ';

export interface ChunkDraft {
  ordinal: number;
  content: string;
  page: number | null;
  sectionPath: string | null;
  tokenCount: number;
}

interface Unit {
  text: string;
  tokens: number;
}

class ChunkBuffer {
  constructor(
    public units: Unit[] = [],
    public overlapUnits = 0,
    public page: number | null = null,
    public section: string | null = null,
  ) {}

  get tokens(): number {
    return this.units.reduce((sum, unit) => sum + unit.tokens, 0);
  }

  get freshTokens(): number {
    return this.units.slice(this.overlapUnits).reduce((sum, unit) => sum + unit.tokens, 0);
  }

  get hasFreshContent(): boolean {
    return this.units.length > this.overlapUnits;
  }
}

export function commonSection(a: string | null, b: string | null): string | null {
  if (a === null || b === null) return null;
  const left = a.split(SECTION_SEPARATOR);
  const right = b.split(SECTION_SEPARATOR);
  const prefix: string[] = [];
  for (let index = 0; index < Math.min(left.length, right.length); index++) {
    if (left[index] !== right[index]) break;
    prefix.push(left[index]!);
  }
  return prefix.length > 0 ? prefix.join(SECTION_SEPARATOR) : null;
}

function leaf(section: string | null): string | null {
  return section ? (section.split(SECTION_SEPARATOR).pop() ?? null) : null;
}

export class Chunker {
  readonly target: number;
  readonly overlap: number;
  readonly minTokens: number;

  constructor(targetTokens = 700, overlapRatio = 0.15, minTokens?: number) {
    if (targetTokens < 50) throw new Error('targetTokens must be >= 50');
    this.target = targetTokens;
    this.overlap = Math.floor(targetTokens * overlapRatio);
    this.minTokens = minTokens ?? Math.max(40, Math.floor(targetTokens / 4));
  }

  chunk(blocks: readonly Block[]): ChunkDraft[] {
    const drafts: ChunkDraft[] = [];
    const stack: Array<[number, string]> = [];
    let buf = new ChunkBuffer();

    const section = (): string | null => (stack.length > 0 ? stack.map(([, text]) => text).join(SECTION_SEPARATOR) : null);

    const canMergeBack = (): boolean => {
      const prev = drafts[drafts.length - 1];
      if (!prev || buf.freshTokens >= this.minTokens) return false;
      const samePage = prev.page === null || buf.page === null || prev.page === buf.page;
      return samePage && prev.tokenCount + buf.freshTokens <= this.target;
    };

    const emit = (carryOverlap: boolean): void => {
      if (!buf.hasFreshContent) {
        buf = new ChunkBuffer([], 0, null, section());
        return;
      }
      const content = buf.units
        .map((unit) => unit.text)
        .join('\n')
        .trim();
      if (!carryOverlap && canMergeBack()) {
        const prev = drafts[drafts.length - 1]!;
        const fresh = buf.units
          .slice(buf.overlapUnits)
          .map((unit) => unit.text)
          .join('\n')
          .trim();
        const heading = buf.section !== prev.sectionPath ? leaf(buf.section) : null;
        const merged = [prev.content, heading, fresh].filter((part): part is string => Boolean(part)).join('\n');
        drafts[drafts.length - 1] = {
          ordinal: prev.ordinal,
          content: merged,
          page: prev.page,
          sectionPath: commonSection(prev.sectionPath, buf.section),
          tokenCount: estimateTokens(merged),
        };
      } else {
        drafts.push({ ordinal: drafts.length, content, page: buf.page, sectionPath: buf.section, tokenCount: estimateTokens(content) });
      }
      const tail = carryOverlap ? this.overlapTail(buf.units) : [];
      buf = new ChunkBuffer(tail, tail.length, buf.page, buf.section);
    };

    for (const block of blocks) {
      if (block.kind === 'heading') {
        const heading = block.text.trim();
        const level = Math.max(1, block.level || 1);
        const absorb = buf.hasFreshContent && buf.freshTokens < this.minTokens && !canMergeBack();
        if (buf.hasFreshContent && !absorb) emit(false);
        while (stack.length > 0 && stack[stack.length - 1]![0] >= level) stack.pop();
        stack.push([level, heading]);
        if (absorb) {
          buf.units.push({ text: heading, tokens: estimateTokens(heading) });
          buf.section = commonSection(buf.section, section());
        } else {
          buf = new ChunkBuffer([], 0, block.page, section());
        }
        continue;
      }

      const pageChanged = block.page !== null && buf.page !== null && block.page !== buf.page;
      if (pageChanged && buf.hasFreshContent && buf.freshTokens >= this.minTokens) emit(false);
      if (!buf.hasFreshContent) {
        const current = section();
        if (buf.section !== current) {
          buf.units = [];
          buf.overlapUnits = 0;
        }
        buf.section = current;
        buf.page = block.page;
      }
      for (const unit of this.units(block)) {
        if (buf.hasFreshContent && buf.tokens + unit.tokens > this.target) {
          emit(true);
          buf.page = block.page;
        }
        buf.units.push(unit);
      }
    }
    emit(false);
    return drafts;
  }

  private units(block: Block): Unit[] {
    const text = block.text.trim();
    const tokens = estimateTokens(text);
    if (tokens <= this.target) return [{ text, tokens }];
    const pieces = block.kind === 'table' || block.kind === 'code' ? text.split('\n') : splitSentences(text);
    const units: Unit[] = [];
    for (const piece of pieces) {
      const pieceTokens = estimateTokens(piece);
      if (pieceTokens <= this.target) {
        units.push({ text: piece, tokens: pieceTokens });
        continue;
      }
      const words = piece.split(/\s+/).filter(Boolean);
      const step = Math.max(1, Math.floor((words.length * this.target) / pieceTokens));
      for (let start = 0; start < words.length; start += step) {
        const part = words.slice(start, start + step).join(' ');
        units.push({ text: part, tokens: estimateTokens(part) });
      }
    }
    return units;
  }

  private overlapTail(units: readonly Unit[]): Unit[] {
    if (this.overlap <= 0) return [];
    const tail: Unit[] = [];
    let total = 0;
    for (let index = units.length - 1; index >= 0; index--) {
      const unit = units[index]!;
      const sentences = splitSentences(unit.text);
      const pieces = sentences.length > 0 ? sentences : [unit.text];
      for (let piece = pieces.length - 1; piece >= 0; piece--) {
        const sentence = pieces[piece]!;
        const tokens = estimateTokens(sentence);
        if (total + tokens > this.overlap) return tail.reverse();
        tail.push({ text: sentence, tokens });
        total += tokens;
      }
    }
    return tail.reverse();
  }
}
