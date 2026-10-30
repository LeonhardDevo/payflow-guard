import { renderDocuments, type Source } from '../agent/sources.js';
import type { RetrievedChunk } from '../retrieval/hybrid.js';
import { ToolError, type Tool, type ToolContext, type ToolResult } from './types.js';

export const MAX_K = 8;

export function sourceFromHit(hit: RetrievedChunk, origin: string): Source {
  return {
    ref: 0,
    documentId: hit.documentId,
    title: hit.title,
    content: hit.content,
    chunkId: hit.chunkId,
    page: hit.page,
    section: hit.sectionPath,
    version: hit.version,
    effectiveDate: hit.effectiveDate,
    score: hit.score,
    confidence: hit.confidence,
    origin,
  };
}

export class SearchDocumentsTool implements Tool {
  readonly name = 'search_documents';
  readonly description =
    "Search the organisation's approved knowledge-base documents (policies, procedures, guides). Use it when the provided sources are missing or insufficient, with a focused query in the language of the documents. Returns numbered sources to cite as [n].";
  readonly parameters = {
    type: 'object',
    properties: {
      query: { type: 'string', description: 'What to look for (keywords or a short question).' },
      k: { type: 'integer', minimum: 1, maximum: MAX_K, description: 'How many passages to return.' },
    },
    required: ['query'],
    additionalProperties: false,
  };
  readonly requiredPermission = null;
  readonly dataTool = false;

  async run(ctx: ToolContext, args: Record<string, unknown>): Promise<ToolResult> {
    const query = typeof args.query === 'string' ? args.query.trim() : '';
    if (query === '') throw new ToolError('query is required');
    const requested = Number(args.k ?? ctx.topK);
    const k = Number.isFinite(requested) ? Math.max(1, Math.min(MAX_K, Math.trunc(requested))) : ctx.topK;
    const result = await ctx.retriever.search({
      workspaceId: ctx.workspaceId,
      query,
      embedder: ctx.embedder,
      viewer: ctx.viewer,
      k,
      locale: ctx.locale,
    });
    const sources = result.hits.map((hit) => ctx.sources.add(sourceFromHit(hit, this.name)));
    if (sources.length === 0) return { content: 'No matching approved documents were found.', confidence: 0 };
    return { content: renderDocuments(sources), sources, confidence: result.confidence };
  }
}
