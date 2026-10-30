import type pg from 'pg';
import type { Logger } from '../../logger.js';
import type { RagDb } from '../db/ragDb.js';
import type { Embedder } from '../embeddings/types.js';
import type { ChunkDraft } from '../ingest/chunker.js';
import { ensureHnswIndex, vectorLiteral } from './indexes.js';

export interface DocumentMeta {
  documentId: number;
  workspaceId: number;
  title: string;
  mime: string;
  department: string | null;
  roles: string[];
  tags: string[];
  version: string | null;
  effectiveDate: string | null;
}

export interface StoredDocument {
  documentId: number;
  chunks: number;
  embeddingModel: string;
  warnings: string[];
}

export interface ChunkPreview {
  ordinal: number;
  page: number | null;
  section: string | null;
  section_path: string | null;
  content: string;
  tokens: number;
}

export class DocumentOwnershipError extends Error {
  readonly retryable = false;

  constructor() {
    super('document belongs to another workspace');
    this.name = 'DocumentOwnershipError';
  }
}

export function embeddingText(title: string, sectionPath: string | null, content: string): string {
  return `${sectionPath ? `${title}\n${sectionPath}` : title}\n${content}`;
}

export class ChunkStore {
  constructor(
    private readonly db: RagDb,
    private readonly logger: Logger,
  ) {}

  private async upsertDocument(client: pg.PoolClient, meta: DocumentMeta): Promise<void> {
    const documents = this.db.table('documents');
    const existing = await client.query<{ workspace_id: number }>(`SELECT workspace_id FROM ${documents} WHERE id = $1 FOR UPDATE`, [meta.documentId]);
    const owner = existing.rows[0];
    if (owner && Number(owner.workspace_id) !== meta.workspaceId) throw new DocumentOwnershipError();
    await client.query(
      `INSERT INTO ${documents} (id, workspace_id, title, mime, department, roles, tags, version, effective_date)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)
       ON CONFLICT (id) DO UPDATE SET title = EXCLUDED.title, mime = EXCLUDED.mime, department = EXCLUDED.department,
         roles = EXCLUDED.roles, tags = EXCLUDED.tags, version = EXCLUDED.version, effective_date = EXCLUDED.effective_date,
         updated_at = now()`,
      [meta.documentId, meta.workspaceId, meta.title, meta.mime, meta.department, meta.roles, meta.tags, meta.version, meta.effectiveDate],
    );
  }

  async markDocument(meta: DocumentMeta, status: 'processing' | 'failed', error: string | null = null): Promise<void> {
    await this.db.transaction(async (client) => {
      await this.upsertDocument(client, meta);
      await client.query(`UPDATE ${this.db.table('documents')} SET status = $2, error = $3, updated_at = now() WHERE id = $1`, [
        meta.documentId,
        status,
        error,
      ]);
    });
  }

  async replaceDocument(
    meta: DocumentMeta,
    drafts: readonly ChunkDraft[],
    vectors: readonly (readonly number[])[],
    embedder: Embedder,
    details: { pages: number | null; ocrUsed: boolean; contentSha256: string; warnings: readonly string[] },
  ): Promise<StoredDocument> {
    if (drafts.length !== vectors.length) throw new Error('drafts and vectors must have the same length');
    const chunks = this.db.table('chunks');
    await this.db.transaction(async (client) => {
      await this.upsertDocument(client, meta);
      await client.query(`DELETE FROM ${chunks} WHERE document_id = $1`, [meta.documentId]);
      await ensureHnswIndex(this.db, client, embedder.modelId, embedder.dims, this.logger);
      for (let start = 0; start < drafts.length; start += 200) {
        const batch = drafts.slice(start, start + 200);
        const values: unknown[] = [];
        const tuples = batch.map((draft, offset) => {
          const base = values.length;
          values.push(
            meta.documentId,
            meta.workspaceId,
            draft.ordinal,
            draft.content,
            meta.title,
            draft.page,
            draft.sectionPath,
            meta.department,
            meta.roles,
            meta.tags,
            meta.version,
            meta.effectiveDate,
            draft.tokenCount,
            vectorLiteral(vectors[start + offset]!),
            embedder.modelId,
            embedder.dims,
          );
          const p = (index: number): string => `$${base + index}`;
          return `(${p(1)}, ${p(2)}, ${p(3)}, ${p(4)}, ${p(5)}, ${p(6)}, ${p(7)}, ${p(8)}, ${p(9)}, ${p(10)}, ${p(11)}, ${p(12)}::date, ${p(13)}, ${p(14)}::vector, ${p(15)}, ${p(16)})`;
        });
        await client.query(
          `INSERT INTO ${chunks} (document_id, workspace_id, ordinal, content, title, page, section_path, department, roles, tags,
             version, effective_date, token_count, embedding, embedding_model, embedding_dims)
           VALUES ${tuples.join(', ')}`,
          values,
        );
      }
      await client.query(
        `UPDATE ${this.db.table('documents')} SET status = 'indexed', pages = $2, chunks_count = $3, ocr_used = $4, error = NULL,
           embedding_model = $5, content_sha256 = $6, warnings = $7::jsonb, indexed_at = now(), updated_at = now()
         WHERE id = $1`,
        [meta.documentId, details.pages, drafts.length, details.ocrUsed, embedder.modelId, details.contentSha256, JSON.stringify(details.warnings)],
      );
    });
    return { documentId: meta.documentId, chunks: drafts.length, embeddingModel: embedder.modelId, warnings: [...details.warnings] };
  }

  async preview(workspaceId: number, documentId: number, limit = 5): Promise<ChunkPreview[]> {
    const { rows } = await this.db.query<{ ordinal: number; page: number | null; section_path: string | null; content: string; token_count: number }>(
      `SELECT ordinal, page, section_path, content, token_count FROM ${this.db.table('chunks')}
       WHERE document_id = $1 AND workspace_id = $2 ORDER BY ordinal LIMIT $3`,
      [documentId, workspaceId, limit],
    );
    return rows.map((row) => ({
      ordinal: row.ordinal,
      page: row.page,
      section: row.section_path,
      section_path: row.section_path,
      content: row.content,
      tokens: row.token_count,
    }));
  }

  async deleteDocument(workspaceId: number, documentId: number): Promise<number> {
    return this.db.transaction(async (client) => {
      const documents = this.db.table('documents');
      const { rows } = await client.query<{ workspace_id: number }>(`SELECT workspace_id FROM ${documents} WHERE id = $1 FOR UPDATE`, [documentId]);
      const owner = rows[0];
      if (!owner) return 0;
      if (Number(owner.workspace_id) !== workspaceId) throw new DocumentOwnershipError();
      const count = await client.query<{ n: number }>(`SELECT count(*)::int AS n FROM ${this.db.table('chunks')} WHERE document_id = $1`, [documentId]);
      await client.query(`DELETE FROM ${documents} WHERE id = $1`, [documentId]);
      return count.rows[0]?.n ?? 0;
    });
  }

  async reembedWorkspace(workspaceId: number, embedder: Embedder, batchSize = 64): Promise<number> {
    const chunks = this.db.table('chunks');
    await this.db.transaction((client) => ensureHnswIndex(this.db, client, embedder.modelId, embedder.dims, this.logger));
    let total = 0;
    let lastId = 0;
    for (;;) {
      const { rows } = await this.db.query<{ id: number; title: string; section_path: string | null; content: string }>(
        `SELECT id, title, section_path, content FROM ${chunks} WHERE workspace_id = $1 AND id > $2 ORDER BY id LIMIT $3`,
        [workspaceId, lastId, batchSize],
      );
      if (rows.length === 0) break;
      const vectors = await embedder.embedDocuments(rows.map((row) => embeddingText(row.title, row.section_path, row.content)));
      await this.db.transaction(async (client) => {
        for (let index = 0; index < rows.length; index++) {
          await client.query(`UPDATE ${chunks} SET embedding = $2::vector, embedding_model = $3, embedding_dims = $4 WHERE id = $1`, [
            rows[index]!.id,
            vectorLiteral(vectors[index]!),
            embedder.modelId,
            embedder.dims,
          ]);
        }
      });
      total += rows.length;
      lastId = Number(rows[rows.length - 1]!.id);
    }
    await this.db.query(`UPDATE ${this.db.table('documents')} SET embedding_model = $2, updated_at = now() WHERE workspace_id = $1 AND chunks_count > 0`, [
      workspaceId,
      embedder.modelId,
    ]);
    return total;
  }
}
