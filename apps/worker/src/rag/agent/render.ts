import MarkdownIt from 'markdown-it';
import { contentTerms, coverage, splitSentences, truncate } from '../text.js';
import type { Source, SourcePool } from './sources.js';

const CITATION = /\[(\d{1,3}(?:\s*[,;]\s*\d{1,3})*)\](?!\()/g;
const MARKDOWN = new MarkdownIt('commonmark', { html: false, linkify: false, typographer: false }).enable('table');
export const SNIPPET_CHARS = 240;

export interface CitedSource {
  number: number;
  source: Source;
}

export interface CitationPayload {
  n: number;
  document_id: number;
  title: string;
  page: number | null;
  section: string | null;
  snippet: string;
  url: string | null;
}

export function renumberCitations(answer: string, pool: SourcePool): { answer: string; cited: CitedSource[] } {
  const mapping = new Map<number, number>();
  const cited: CitedSource[] = [];
  const rewritten = answer.replace(CITATION, (_match, group: string) => {
    const numbers: number[] = [];
    for (const raw of group.split(/\s*[,;]\s*/)) {
      const ref = Number(raw);
      const source = pool.get(ref);
      if (!source) continue;
      if (!mapping.has(ref)) {
        mapping.set(ref, mapping.size + 1);
        cited.push({ number: mapping.get(ref)!, source });
      }
      const number = mapping.get(ref)!;
      if (!numbers.includes(number)) numbers.push(number);
    }
    return numbers.map((number) => `[${number}]`).join('');
  });
  return { answer: rewritten.replace(/[ \t]+([.,;:!?])/g, '$1').trim(), cited };
}

export function snippetFor(source: Source, question: string): string {
  const terms = contentTerms(question);
  const sentences = splitSentences(source.content);
  const candidates = sentences.length > 0 ? sentences : [source.content];
  let best = candidates[0] ?? '';
  if (terms.length > 0) {
    let bestScore = -1;
    for (const sentence of candidates) {
      const score = coverage(terms, sentence);
      if (score > bestScore) {
        best = sentence;
        bestScore = score;
      }
    }
  }
  return truncate(best, SNIPPET_CHARS);
}

export function citationPayload(cited: CitedSource, question: string, publicWebUrl: string): CitationPayload {
  const { source } = cited;
  return {
    n: cited.number,
    document_id: source.documentId,
    title: source.title,
    page: source.page,
    section: source.section,
    snippet: snippetFor(source, question),
    url: publicWebUrl ? `${publicWebUrl}/knowledge/${source.documentId}` : null,
  };
}

export function markdownToHtml(markdown: string): string {
  return MARKDOWN.render(markdown).trim();
}
