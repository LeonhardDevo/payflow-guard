import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { createSilentLogger } from '../../src/logger.js';
import { migrateRag } from '../../src/rag/db/migrations.js';
import { RagDb } from '../../src/rag/db/ragDb.js';
import { HashEmbedder } from '../../src/rag/embeddings/hash.js';
import type { ChunkDraft } from '../../src/rag/ingest/chunker.js';
import { familyKey, resolveConflicts } from '../../src/rag/retrieval/conflicts.js';
import { HybridRetriever, type Viewer } from '../../src/rag/retrieval/hybrid.js';
import { forgetKnownIndexes, indexName } from '../../src/rag/retrieval/indexes.js';
import { ChunkStore, DocumentOwnershipError, embeddingText, type DocumentMeta } from '../../src/rag/retrieval/store.js';
import { PrivacyPurger } from '../../src/rag/privacy/purge.js';
import { announceSkip, testDatabaseUrl } from '../helpers/infra.js';
import { ragDatabase, type RagDatabase } from './support/harness.js';

const database = await ragDatabase();
const skip = database ? '' : announceSkip('Postgres (RAG retrieval tests)', testDatabaseUrl());
const EMBEDDER = new HashEmbedder();
const MEMBER: Viewer = { roles: ['member'], privileged: false };
const logger = createSilentLogger();

function meta(documentId: number, workspaceId: number, title: string, extra: Partial<DocumentMeta> = {}): DocumentMeta {
  return { documentId, workspaceId, title, mime: 'text/plain', department: null, roles: [], tags: [], version: null, effectiveDate: null, ...extra };
}

describe.skipIf(!database)(`hybrid retrieval on Postgres${skip}`, () => {
  let db: RagDb;
  let store: ChunkStore;
  let retriever: HybridRetriever;

  async function storeDoc(documentMeta: DocumentMeta, contents: string[], sections?: string[]): Promise<void> {
    const drafts: ChunkDraft[] = contents.map((content, index) => ({
      ordinal: index,
      content,
      page: index + 1,
      sectionPath: sections?.[index] ?? null,
      tokenCount: 20,
    }));
    const vectors = await EMBEDDER.embedDocuments(drafts.map((draft) => embeddingText(documentMeta.title, draft.sectionPath, draft.content)));
    await store.replaceDocument(documentMeta, drafts, vectors, EMBEDDER, { pages: drafts.length, ocrUsed: false, contentSha256: 'x'.repeat(64), warnings: [] });
  }

  async function corpus(): Promise<void> {
    await storeDoc(
      meta(1, 100, 'Callback policy', { department: 'policy', tags: ['lang:en'] }),
      ['Call the vendor back on the phone number from the signed contract before any bank detail change.', 'Two approvers are required for payments above 2,500 USD.'],
      ['Callback policy > Verification', 'Callback policy > Approvals'],
    );
    await storeDoc(meta(2, 100, 'Регламент смены реквизитов', { department: 'procedure', tags: ['lang:ru'] }), [
      'При смене банковских реквизитов поставщика бухгалтер звонит по номеру из договора.',
      'Платёж приостанавливается до подтверждения.',
    ]);
    await storeDoc(meta(3, 100, 'Vendor register', { department: 'vendor_master', roles: ['owner', 'bookkeeper'] }), [
      'Blue Ridge Packaging official callback contact is listed in contract section 9.2.',
    ]);
    await storeDoc(meta(4, 200, 'Callback policy', { department: 'policy' }), ['Other workspace: call any number from the invoice.']);
  }

  async function search(query: string, viewer: Viewer = MEMBER, extra: Partial<Parameters<HybridRetriever['search']>[0]> = {}): Promise<number[]> {
    const result = await retriever.search({ workspaceId: 100, query, embedder: EMBEDDER, viewer, ...extra });
    return result.hits.map((hit) => hit.documentId);
  }

  beforeAll(async () => {
    await migrateRag(database!.pool, logger, database!.schema);
    db = new RagDb(database!.pool, database!.schema);
    store = new ChunkStore(db, logger);
    retriever = new HybridRetriever(db, { candidates: 40, rrfK: 60 });
  });

  beforeEach(async () => {
    await db.query(`TRUNCATE ${db.table('chunks')}, ${db.table('documents')} RESTART IDENTITY CASCADE`);
  });

  afterAll(async () => {
    forgetKnownIndexes();
    await (database as RagDatabase).drop();
  });

  it('ranks the relevant chunk first with fused scores and confidence', async () => {
    await corpus();
    const result = await retriever.search({ workspaceId: 100, query: 'Which phone number do I use to call the vendor back?', embedder: EMBEDDER, viewer: MEMBER, k: 3 });
    const top = result.hits[0]!;
    expect(top.documentId).toBe(1);
    expect(top.content).toContain('signed contract');
    expect(top.sectionPath).toBe('Callback policy > Verification');
    expect(top.page).toBe(1);
    expect(top.vectorSimilarity).not.toBeNull();
    expect(top.ftsRank).not.toBeNull();
    expect(result.confidence).toBeGreaterThanOrEqual(0.35);
    expect(result.hits.map((hit) => hit.score)).toEqual([...result.hits.map((hit) => hit.score)].sort((a, b) => b - a));
  });

  it('uses Russian FTS and stemming for Russian questions', async () => {
    await corpus();
    const result = await retriever.search({ workspaceId: 100, query: 'Что делать при смене реквизитов поставщика?', embedder: EMBEDDER, viewer: MEMBER, k: 3 });
    expect(result.hits[0]!.documentId).toBe(2);
    expect(result.confidence).toBeGreaterThanOrEqual(0.35);
  });

  it('never returns another workspace', async () => {
    await corpus();
    for (const query of ['call vendor number', 'Other workspace: call any number from the invoice']) {
      expect(await search(query, { roles: [], privileged: true })).not.toContain(4);
    }
  });

  it('applies role filters', async () => {
    await corpus();
    const query = 'Blue Ridge Packaging official callback contact';
    expect(await search(query)).not.toContain(3);
    expect((await search(query, { roles: ['bookkeeper'], privileged: false }))[0]).toBe(3);
    expect((await search(query, { roles: [], privileged: true }))[0]).toBe(3);
  });

  it('filters by department, tags and documents', async () => {
    await corpus();
    expect(new Set(await search('vendor number contract', MEMBER, { filters: { department: 'policy' } }))).toEqual(new Set([1]));
    expect(new Set(await search('vendor', MEMBER, { filters: { documentIds: [2] } }))).toEqual(new Set([2]));
    expect(await search('vendor', MEMBER, { filters: { tags: ['nope'] } })).toEqual([]);
    expect(new Set(await search('поставщика реквизитов', MEMBER, { filters: { tags: ['lang:ru'] } }))).toEqual(new Set([2]));
  });

  it('gives unrelated questions a low confidence', async () => {
    await corpus();
    const result = await retriever.search({ workspaceId: 100, query: 'What is the parking fee at the airport?', embedder: EMBEDDER, viewer: MEMBER });
    expect(result.confidence).toBeLessThan(0.35);
  });

  it('lets the newer effective date win conflicts and reports them', async () => {
    await storeDoc(meta(10, 100, 'Approval policy v1', { version: '1', effectiveDate: '2024-01-01' }), ['Payments above 5,000 USD need two approvers.']);
    await storeDoc(meta(11, 100, 'Approval policy v2', { version: '2', effectiveDate: '2025-06-01' }), ['Payments above 2,500 USD need two approvers.']);
    const result = await retriever.search({ workspaceId: 100, query: 'When do payments need two approvers?', embedder: EMBEDDER, viewer: MEMBER });
    expect(result.hits.map((hit) => hit.documentId)).toEqual([11]);
    expect(result.conflicts).toHaveLength(1);
    expect([result.conflicts[0]!.winnerDocumentId, result.conflicts[0]!.supersededDocumentId]).toEqual([11, 10]);
    expect(result.conflicts[0]!.supersededEffectiveDate).toBe('2024-01-01');
    const unresolved = await retriever.search({ workspaceId: 100, query: 'payments two approvers', embedder: EMBEDDER, viewer: MEMBER, resolve: false });
    expect(new Set(unresolved.hits.map((hit) => hit.documentId))).toEqual(new Set([10, 11]));
  });

  it('applies the post-fusion hook before conflicts are resolved', async () => {
    await corpus();
    const hooked = new HybridRetriever(db, { candidates: 40, rrfK: 60, postFusion: (hits, ctx) => hits.filter((hit) => hit.tags.includes(`lang:${ctx.locale ?? 'en'}`)) });
    const result = await hooked.search({ workspaceId: 100, query: 'vendor bank details', embedder: EMBEDDER, viewer: MEMBER, locale: 'ru' });
    expect(new Set(result.hits.map((hit) => hit.documentId))).toEqual(new Set([2]));
  });

  it('creates a partial HNSW index per model and replaces chunks on re-ingest', async () => {
    await corpus();
    const name = indexName(EMBEDDER.modelId, 384);
    const { rows } = await db.query<{ indexdef: string }>('SELECT indexdef FROM pg_indexes WHERE schemaname = $1 AND indexname = $2', [db.schema, name]);
    expect(rows[0]?.indexdef).toContain('hnsw');
    expect(rows[0]?.indexdef).toContain('vector(384)');
    expect(rows[0]?.indexdef).toContain('hash:hash-384');
    await storeDoc(meta(1, 100, 'Callback policy'), ['Replaced content only.']);
    const count = await db.query<{ n: number }>(`SELECT count(*)::int AS n FROM ${db.table('chunks')} WHERE document_id = 1`);
    expect(count.rows[0]!.n).toBe(1);
  });

  it('enforces document ownership and deletes chunks', async () => {
    await corpus();
    await expect(store.deleteDocument(200, 1)).rejects.toThrow(DocumentOwnershipError);
    await expect(storeDoc(meta(1, 200, 'Hijack'), ['x'])).rejects.toThrow(DocumentOwnershipError);
    expect(await store.deleteDocument(100, 1)).toBe(2);
    expect(await store.deleteDocument(100, 1)).toBe(0);
    expect(await search('call vendor number contract')).not.toContain(1);
  });

  it('re-embeds a workspace with another model', async () => {
    await corpus();
    const other = new HashEmbedder(256);
    expect(await store.reembedWorkspace(100, other, 2)).toBe(5);
    const fresh = await retriever.search({ workspaceId: 100, query: 'call the vendor phone number contract', embedder: other, viewer: MEMBER });
    expect(fresh.hits[0]!.documentId).toBe(1);
    expect(fresh.hits[0]!.vectorSimilarity).not.toBeNull();
    const old = await retriever.search({ workspaceId: 100, query: 'call the vendor phone number contract', embedder: EMBEDDER, viewer: MEMBER });
    expect(old.hits.every((hit) => hit.vectorSimilarity === null)).toBe(true);
    expect(await store.reembedWorkspace(200, other)).toBe(1);
  });

  it('purges a workspace completely', async () => {
    await corpus();
    await db.query(`INSERT INTO ${db.table('conversation_turns')} (workspace_id, conversation_id, role, content) VALUES (100, 'c', 'user', 'q')`);
    await db.query(`INSERT INTO ${db.table('ai_usage')} (workspace_id, provider, model, purpose, request_id) VALUES (100, 'mock', 'm', 'chat', 'r')`);
    expect(await new PrivacyPurger(db).purgeWorkspace(100)).toEqual({ documents: 3, chunks: 5, conversation_turns: 1, usage_rows: 1 });
    const usage = await db.query<{ n: number }>(`SELECT count(*)::int AS n FROM ${db.table('ai_usage')} WHERE workspace_id = 100`);
    expect(usage.rows[0]!.n).toBe(0);
    const left = await db.query<{ n: number }>(`SELECT count(*)::int AS n FROM ${db.table('chunks')} WHERE workspace_id = 100`);
    expect(left.rows[0]!.n).toBe(0);
    expect(await search('vendor', { roles: [], privileged: true })).toEqual([]);
    expect((await retriever.search({ workspaceId: 200, query: 'invoice number', embedder: EMBEDDER, viewer: MEMBER })).hits).toHaveLength(1);
  });
});

describe('conflict families', () => {
  it('groups versions of the same document', () => {
    expect(familyKey('Leave policy v2 (2025)', 'HR')).toBe(familyKey('Leave Policy', 'hr'));
    expect(familyKey('Регламент ред. 3 (01.02.2025)', null)).toBe(familyKey('Регламент', null));
    const base = { department: null, version: null };
    const { kept, conflicts } = resolveConflicts([
      { ...base, documentId: 1, title: 'Policy', effectiveDate: '2024-01-01' },
      { ...base, documentId: 2, title: 'Policy v2', effectiveDate: '2024-01-01', version: '10' },
      { ...base, documentId: 3, title: 'Policy v1', effectiveDate: '2024-01-01', version: '9' },
      { ...base, documentId: 4, title: 'Other', effectiveDate: null },
    ]);
    expect(kept.map((hit) => hit.documentId)).toEqual([2, 4]);
    expect(conflicts.map((conflict) => conflict.supersededDocumentId).sort()).toEqual([1, 3]);
  });
});
