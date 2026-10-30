import type { RagDb } from '../db/ragDb.js';
import { calibrate, type Embedder } from '../embeddings/types.js';
import type { RagMetrics } from '../metrics.js';
import { contentTerms, coverage, queryLexemes } from '../text.js';
import { resolveConflicts, type Conflict } from './conflicts.js';
import { HNSW_MAX_DIMS, sqlModelLiteral, vectorLiteral } from './indexes.js';

export const VECTOR_WEIGHT = 0.55;
export const LEXICAL_WEIGHT = 0.45;
export const CONFIDENCE_TOP_N = 3;

export interface SearchFilters {
  department?: string | null;
  tags?: readonly string[];
  documentIds?: readonly number[];
}

export interface Viewer {
  roles: readonly string[];
  privileged: boolean;
}

export interface RetrievedChunk {
  chunkId: number;
  documentId: number;
  title: string;
  content: string;
  page: number | null;
  sectionPath: string | null;
  department: string | null;
  roles: string[];
  tags: string[];
  version: string | null;
  effectiveDate: string | null;
  score: number;
  vectorSimilarity: number | null;
  ftsRank: number | null;
  confidence: number;
}

export interface RetrievalResult {
  hits: RetrievedChunk[];
  confidence: number;
  conflicts: Conflict[];
  embeddingModel: string;
  latencyMs: number;
}

export interface SearchParams {
  workspaceId: number;
  query: string;
  embedder: Embedder;
  viewer: Viewer;
  k?: number;
  filters?: SearchFilters;
  resolve?: boolean;
  locale?: 'ru' | 'en';
}

export interface PostFusionContext {
  workspaceId: number;
  query: string;
  viewer: Viewer;
  locale: 'ru' | 'en' | null;
}

export type PostFusionHook = (hits: RetrievedChunk[], ctx: PostFusionContext) => RetrievedChunk[];

interface HitRow {
  id: number;
  document_id: number;
  title: string;
  content: string;
  page: number | null;
  section_path: string | null;
  department: string | null;
  roles: string[] | null;
  tags: string[] | null;
  version: string | null;
  effective_date: string | null;
  score: string | number;
  sim: string | number | null;
  fts_rank: string | number | null;
}

export function chunkToJson(hit: RetrievedChunk): Record<string, unknown> {
  const round = (value: number, digits: number): number => Number(value.toFixed(digits));
  return {
    chunk_id: hit.chunkId,
    document_id: hit.documentId,
    title: hit.title,
    content: hit.content,
    page: hit.page,
    section_path: hit.sectionPath,
    department: hit.department,
    roles: hit.roles,
    tags: hit.tags,
    version: hit.version,
    effective_date: hit.effectiveDate,
    score: round(hit.score, 6),
    vector_similarity: hit.vectorSimilarity === null ? null : round(hit.vectorSimilarity, 4),
    fts_rank: hit.ftsRank === null ? null : round(hit.ftsRank, 4),
    confidence: round(hit.confidence, 4),
  };
}

export class HybridRetriever {
  constructor(
    private readonly db: RagDb,
    private readonly options: { candidates: number; rrfK: number; postFusion?: PostFusionHook | null; metrics?: RagMetrics } = {
      candidates: 40,
      rrfK: 60,
    },
  ) {}

  private hybridSql(modelId: string, dims: number, withVector: boolean): string {
    const chunks = this.db.table('chunks');
    const unaccent = this.db.unaccent;
    const acl = `
      c.workspace_id = $1
      AND ($2::boolean OR cardinality(c.roles) = 0 OR c.roles && $3::text[])
      AND ($4::text IS NULL OR c.department = $4::text)
      AND ($5::text[] IS NULL OR c.tags && $5::text[])
      AND ($6::bigint[] IS NULL OR c.document_id = ANY($6::bigint[]))`;
    let vecCte: string;
    if (withVector) {
      const cast = dims <= HNSW_MAX_DIMS ? `vector(${Math.trunc(dims)})` : 'vector';
      vecCte = `
      vec AS (
        SELECT id, row_number() OVER (ORDER BY dist, id) AS rnk, 1 - dist AS sim FROM (
          SELECT c.id, (c.embedding::${cast} <=> $12::${cast}) AS dist
          FROM ${chunks} c
          WHERE c.embedding_model = ${sqlModelLiteral(modelId)} AND ${acl}
          ORDER BY c.embedding::${cast} <=> $12::${cast}
          LIMIT $7
        ) v
      ),`;
    } else {
      vecCte = `vec AS (SELECT NULL::bigint AS id, NULL::bigint AS rnk, NULL::float8 AS sim WHERE $12::text IS NULL AND false),`;
    }
    return `
      WITH ${vecCte}
      q AS (
        SELECT to_tsquery('simple', ${unaccent}($9)) AS qs,
               to_tsquery('russian', ${unaccent}($9)) AS qr,
               to_tsquery('english', ${unaccent}($9)) AS qe
      ),
      fts AS (
        SELECT id, row_number() OVER (ORDER BY rank DESC, id) AS rnk, rank FROM (
          SELECT c.id,
                 ts_rank_cd(c.tsv_simple, q.qs, 32) + ts_rank_cd(c.tsv_russian, q.qr, 32) + ts_rank_cd(c.tsv_english, q.qe, 32) AS rank
          FROM ${chunks} c, q
          WHERE $10::boolean AND ${acl}
            AND (c.tsv_simple @@ q.qs OR c.tsv_russian @@ q.qr OR c.tsv_english @@ q.qe)
          ORDER BY rank DESC
          LIMIT $7
        ) f
      ),
      fused AS (
        SELECT id, SUM(1.0 / ($8 + rnk)) AS score
        FROM (SELECT id, rnk FROM vec UNION ALL SELECT id, rnk FROM fts) u
        GROUP BY id
      )
      SELECT c.id, c.document_id, c.title, c.content, c.page, c.section_path, c.department, c.roles, c.tags,
             c.version, c.effective_date::text AS effective_date, fused.score, vec.sim, fts.rank AS fts_rank
      FROM fused
      JOIN ${chunks} c ON c.id = fused.id
      LEFT JOIN vec ON vec.id = fused.id
      LEFT JOIN fts ON fts.id = fused.id
      ORDER BY fused.score DESC, c.id
      LIMIT $11`;
  }

  async search(params: SearchParams): Promise<RetrievalResult> {
    const started = performance.now();
    const k = params.k ?? 6;
    const filters = params.filters ?? {};
    const lexemes = queryLexemes(params.query);
    const queryVector = params.query.trim() !== '' ? await params.embedder.embedQuery(params.query) : null;
    const withVector = queryVector !== null && queryVector.some((value) => value !== 0);
    const values: unknown[] = [
      params.workspaceId,
      params.viewer.privileged,
      [...params.viewer.roles],
      filters.department ?? null,
      filters.tags && filters.tags.length > 0 ? [...filters.tags] : null,
      filters.documentIds && filters.documentIds.length > 0 ? [...filters.documentIds] : null,
      Math.max(this.options.candidates, k * 4),
      this.options.rrfK,
      lexemes.length > 0 ? lexemes.join(' | ') : '',
      lexemes.length > 0,
      Math.max(k * 3, k),
      withVector ? vectorLiteral(queryVector) : null,
    ];
    const sql = this.hybridSql(params.embedder.modelId, params.embedder.dims, withVector);
    const client = await this.db.pool.connect();
    let rows: HitRow[];
    try {
      await client.query('BEGIN READ ONLY');
      if (withVector) {
        await client.query('SET LOCAL hnsw.ef_search = 100');
        await client.query("SET LOCAL hnsw.iterative_scan = 'relaxed_order'");
      }
      rows = (await client.query<HitRow>(sql, values)).rows;
      await client.query('ROLLBACK');
    } catch (error) {
      await client.query('ROLLBACK').catch(() => undefined);
      throw error;
    } finally {
      client.release();
    }

    const terms = contentTerms(params.query);
    let hits: RetrievedChunk[] = rows.map((row) => {
      const hit: RetrievedChunk = {
        chunkId: Number(row.id),
        documentId: Number(row.document_id),
        title: row.title,
        content: row.content,
        page: row.page,
        sectionPath: row.section_path,
        department: row.department,
        roles: row.roles ?? [],
        tags: row.tags ?? [],
        version: row.version,
        effectiveDate: row.effective_date,
        score: Number(row.score),
        vectorSimilarity: row.sim === null ? null : Number(row.sim),
        ftsRank: row.fts_rank === null ? null : Number(row.fts_rank),
        confidence: 0,
      };
      hit.confidence = hitConfidence(hit, terms, params.embedder);
      return hit;
    });
    if (this.options.postFusion) {
      hits = this.options.postFusion(hits, {
        workspaceId: params.workspaceId,
        query: params.query,
        viewer: params.viewer,
        locale: params.locale ?? null,
      });
    }
    let conflicts: Conflict[] = [];
    if (params.resolve ?? true) {
      const resolved = resolveConflicts(hits);
      hits = resolved.kept;
      conflicts = resolved.conflicts;
    }
    hits = hits.slice(0, k);
    const confidence = hits.slice(0, CONFIDENCE_TOP_N).reduce((max, hit) => Math.max(max, hit.confidence), 0);
    const elapsed = performance.now() - started;
    this.options.metrics?.retrievalDuration.observe(elapsed / 1000);
    return {
      hits,
      confidence,
      conflicts: conflicts.filter((conflict) => hits.some((hit) => hit.documentId === conflict.winnerDocumentId)),
      embeddingModel: params.embedder.modelId,
      latencyMs: Math.round(elapsed),
    };
  }
}

export function hitConfidence(hit: RetrievedChunk, terms: readonly string[], embedder: Embedder): number {
  const vectorPart = hit.vectorSimilarity === null ? 0 : calibrate(hit.vectorSimilarity, embedder.calibration);
  const lexicalPart = coverage(terms, `${hit.title} ${hit.sectionPath ?? ''} ${hit.content}`);
  return Math.round((VECTOR_WEIGHT * vectorPart + LEXICAL_WEIGHT * lexicalPart) * 10_000) / 10_000;
}
