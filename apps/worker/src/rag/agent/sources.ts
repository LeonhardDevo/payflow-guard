export const NOT_FOUND_MARKER = '[[NOT_FOUND]]';

const SOURCE_RE = /<source id="(\d+)"([^>]*)>\n?([\s\S]*?)\n?<\/source>/g;
const ATTR_RE = /(\w+)="([^"]*)"/g;

export interface Source {
  ref: number;
  documentId: number;
  title: string;
  content: string;
  chunkId: number | null;
  page: number | null;
  section: string | null;
  version: string | null;
  effectiveDate: string | null;
  score: number;
  confidence: number;
  origin: string;
}

export class SourcePool {
  readonly sources: Source[] = [];
  private readonly byChunk = new Map<number, Source>();

  add(source: Source): Source {
    if (source.chunkId !== null) {
      const existing = this.byChunk.get(source.chunkId);
      if (existing) {
        existing.confidence = Math.max(existing.confidence, source.confidence);
        return existing;
      }
    }
    source.ref = this.sources.length + 1;
    this.sources.push(source);
    if (source.chunkId !== null) this.byChunk.set(source.chunkId, source);
    return source;
  }

  get(ref: number): Source | undefined {
    return ref >= 1 && ref <= this.sources.length ? this.sources[ref - 1] : undefined;
  }

  get maxConfidence(): number {
    return this.sources.reduce((max, source) => Math.max(max, source.confidence), 0);
  }
}

export function escapeHtml(value: string): string {
  return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#x27;');
}

export function unescapeHtml(value: string): string {
  return value
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#x27;/g, "'")
    .replace(/&#39;/g, "'")
    .replace(/&amp;/g, '&');
}

export function renderSource(source: Source): string {
  const attrs = [`id="${source.ref}"`, `title="${escapeHtml(source.title)}"`];
  if (source.page !== null) attrs.push(`page="${source.page}"`);
  if (source.section) attrs.push(`section="${escapeHtml(source.section)}"`);
  if (source.version) attrs.push(`version="${escapeHtml(source.version)}"`);
  if (source.effectiveDate) attrs.push(`effective_date="${source.effectiveDate}"`);
  return `<source ${attrs.join(' ')}>\n${escapeHtml(source.content)}\n</source>`;
}

export function renderDocuments(sources: readonly Source[]): string {
  if (sources.length === 0) return '<documents>\n</documents>';
  return `<documents>\n${sources.map(renderSource).join('\n')}\n</documents>`;
}

export interface ParsedSource {
  ref: number;
  title: string;
  content: string;
  attrs: Record<string, string>;
}

export function parseSources(text: string): ParsedSource[] {
  const parsed: ParsedSource[] = [];
  for (const match of text.matchAll(SOURCE_RE)) {
    const attrs: Record<string, string> = {};
    for (const attr of (match[2] ?? '').matchAll(ATTR_RE)) {
      if (attr[1]) attrs[attr[1]] = unescapeHtml(attr[2] ?? '');
    }
    parsed.push({ ref: Number(match[1]), title: attrs.title ?? '', content: unescapeHtml(match[3] ?? ''), attrs });
  }
  return parsed;
}
