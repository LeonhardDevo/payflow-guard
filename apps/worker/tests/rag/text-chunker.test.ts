import { describe, expect, it } from 'vitest';
import { block, headingLevel, linesToBlocks, type Block } from '../../src/rag/ingest/blocks.js';
import { Chunker } from '../../src/rag/ingest/chunker.js';
import { contentTerms, coverage, estimateTokens, fold, queryLexemes, splitSentences, stem, truncate } from '../../src/rag/text.js';

function paragraph(words: number, word = 'lorem', page: number | null = null): Block {
  const sentences: string[] = [];
  for (let index = 0; index < words; index += 10) {
    const text = `${Array.from({ length: Math.min(10, words - index) }, () => word).join(' ')}.`;
    sentences.push(text.charAt(0).toUpperCase() + text.slice(1));
  }
  return block(sentences.join(' '), 'paragraph', 0, page);
}

describe('bilingual text helpers', () => {
  it('folds accents and stems Russian and English words', () => {
    expect(fold('Ёлка Café')).toBe('елка cafe');
    expect(stem('отпуска')).toBe('отпуск');
    expect(stem('отпуск')).toBe('отпуск');
    expect(stem('сотрудникам')).toBe(stem('сотруднику'));
    expect(stem('employees').startsWith('employe')).toBe(true);
    expect(contentTerms('How many vacation days do I get?')).toEqual(['many', 'vacation', 'day']);
    expect(contentTerms('Сколько дней отпуска?')).not.toContain('сколько');
  });

  it('measures coverage and builds safe lexemes', () => {
    const terms = contentTerms('Сколько дней отпуска положено сотруднику?');
    expect(coverage(terms, 'Сотрудникам предоставляется отпуск 28 календарных дней.')).toBeCloseTo(0.75);
    expect(coverage([], 'anything')).toBe(0);
    expect(queryLexemes('What is the refund LIMIT for SP-2024?')).toEqual(['refund', 'limit', 'sp', '2024']);
  });

  it('splits sentences and estimates tokens', () => {
    expect(splitSentences('First one. Second one! Третье? 4th')).toEqual(['First one.', 'Second one!', 'Третье?', '4th']);
    expect(estimateTokens('')).toBe(0);
    const estimate = estimateTokens('one two three four five six, seven.');
    expect(estimate).toBeGreaterThanOrEqual(8);
    expect(estimate).toBeLessThanOrEqual(12);
    expect(truncate('alpha beta gamma delta', 12)).toBe('alpha beta…');
  });
});

describe('structure detection', () => {
  it.each([
    ['1. Introduction', 1],
    ['2.1 Annual leave', 2],
    ['3.2.1 Carry-over rules', 3],
    ['Раздел 4. Командировки', 1],
    ['GENERAL PROVISIONS', 1],
    ['1. Submit the form to HR.', 0],
    ['This is a normal sentence that happens to be fairly short', 0],
  ])('heading level of %s is %i', (line, level) => {
    expect(headingLevel(line)).toBe(level);
  });

  it('rebuilds paragraphs, lists and hyphenation from lines', () => {
    const blocks = linesToBlocks('1. Scope\nThis policy applies to all\nemployees and contrac-\ntors.\n\n- first item\n- second item\ncontinued', 3);
    expect(blocks.map((item) => [item.kind, item.text])).toEqual([
      ['heading', '1. Scope'],
      ['paragraph', 'This policy applies to all employees and contractors.'],
      ['list', '- first item'],
      ['list', '- second item continued'],
    ]);
    expect(blocks.every((item) => item.page === 3)).toBe(true);
  });
});

describe('structure-aware chunker', () => {
  it('tracks the section path and merges tiny sections', () => {
    const chunks = new Chunker(700).chunk([
      block('Leave policy', 'heading', 1),
      block('1. Annual leave', 'heading', 2),
      paragraph(200, 'annual'),
      block('1.1 Carry over', 'heading', 3),
      block('Unused days can be carried over once.'),
      block('2. Sick leave', 'heading', 2),
      paragraph(200, 'sick'),
    ]);
    expect(chunks.map((chunk) => chunk.sectionPath)).toEqual(['Leave policy > 1. Annual leave', 'Leave policy > 2. Sick leave']);
    expect(chunks[0]!.content).toContain('1.1 Carry over');
    expect(chunks[0]!.content).toContain('carried over once');
    expect(chunks.every((chunk, index) => chunk.ordinal === index)).toBe(true);
  });

  it('respects the target size and carries a sentence overlap', () => {
    const blocks = [block('Handbook', 'heading', 1), ...Array.from({ length: 12 }, (_, index) => paragraph(120, `w${index}`))];
    const chunks = new Chunker(300, 0.15).chunk(blocks);
    expect(chunks.length).toBeGreaterThanOrEqual(4);
    expect(chunks.every((chunk) => chunk.tokenCount <= 360)).toBe(true);
    for (let index = 1; index < chunks.length; index++) {
      const tail = chunks[index - 1]!.content.split('. ').pop()!.replace(/^[. ]+|[. ]+$/g, '');
      expect(chunks[index]!.content.startsWith(tail)).toBe(true);
    }
  });

  it('splits on pages and keeps page numbers', () => {
    const blocks = [paragraph(150, 'alpha', 1), paragraph(150, 'beta', 2), block('Tiny tail.', 'paragraph', 0, 2)];
    const chunks = new Chunker(700).chunk(blocks);
    expect(chunks.map((chunk) => chunk.page)).toEqual([1, 2]);
    expect(chunks[1]!.content).toContain('Tiny tail.');
    expect(new Chunker(700).chunk([...blocks, block('Signed by the CEO.', 'paragraph', 0, 3)]).map((chunk) => chunk.page)).toEqual([1, 2, 3]);
  });

  it('carries a tiny intro into the first section', () => {
    const chunks = new Chunker(700).chunk([
      block('Handbook', 'heading', 1),
      block('Read this first.'),
      block('1. Basics', 'heading', 2),
      paragraph(60, 'basic'),
    ]);
    expect(chunks).toHaveLength(1);
    expect(chunks[0]!.sectionPath).toBe('Handbook');
    expect(chunks[0]!.content.startsWith('Read this first.\n1. Basics\n')).toBe(true);
  });

  it('splits oversized tables by rows', () => {
    const rows = Array.from({ length: 400 }, (_, index) => `row ${index} | value ${index} | note`).join('\n');
    const chunks = new Chunker(200).chunk([block(rows, 'table')]);
    expect(chunks.length).toBeGreaterThan(3);
    expect(chunks[0]!.content.startsWith('row 0 | value 0')).toBe(true);
  });

  it('rejects silly targets and handles empty input', () => {
    expect(() => new Chunker(10)).toThrow(/targetTokens/);
    expect(new Chunker().chunk([])).toEqual([]);
    expect(new Chunker().chunk([block('Only a heading', 'heading', 1)])).toEqual([]);
  });
});
