import { createHash } from 'node:crypto';
import type pg from 'pg';
import type { Logger } from '../../logger.js';
import type { RagDb } from '../db/ragDb.js';

export const HNSW_MAX_DIMS = 2000;
const SAFE_MODEL = /^[\w.:/@+-]{1,200}$/;

export class UnsafeModelIdError extends Error {
  constructor(modelId: string) {
    super(`unsupported characters in embedding model id: ${JSON.stringify(modelId)}`);
    this.name = 'UnsafeModelIdError';
  }
}

export function sqlModelLiteral(modelId: string): string {
  if (!SAFE_MODEL.test(modelId)) throw new UnsafeModelIdError(modelId);
  return `'${modelId.replace(/'/g, "''")}'`;
}

export function indexName(modelId: string, dims: number): string {
  const digest = createHash('sha1').update(modelId, 'utf8').digest('hex').slice(0, 12);
  return `ix_chunks_hnsw_${digest}_${dims}`;
}

export function vectorLiteral(values: readonly number[]): string {
  return `[${values.map((value) => (Number.isFinite(value) ? Number(value.toPrecision(7)) : 0)).join(',')}]`;
}

const known = new Set<string>();

export async function ensureHnswIndex(db: RagDb, client: pg.PoolClient, modelId: string, dims: number, logger: Logger): Promise<string | null> {
  if (dims > HNSW_MAX_DIMS) {
    logger.warn({ model: modelId, dims }, 'hnsw index skipped: dimensions above the HNSW limit');
    return null;
  }
  const name = indexName(modelId, dims);
  const cacheKey = `${db.schema}::${name}`;
  if (known.has(cacheKey)) return name;
  await client.query('SELECT pg_advisory_xact_lock(hashtext($1))', [cacheKey]);
  await client.query(
    `CREATE INDEX IF NOT EXISTS ${name} ON ${db.table('chunks')} ` +
      `USING hnsw ((embedding::vector(${Math.trunc(dims)})) vector_cosine_ops) ` +
      `WHERE embedding_model = ${sqlModelLiteral(modelId)}`,
  );
  known.add(cacheKey);
  logger.info({ index: name, model: modelId, dims }, 'hnsw index ready');
  return name;
}

export function forgetKnownIndexes(): void {
  known.clear();
}
