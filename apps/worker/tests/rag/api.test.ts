import { UnrecoverableError } from 'bullmq';
import Fastify, { type FastifyInstance } from 'fastify';
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest';
import type { WebApi } from '../../src/clients/web.js';
import type { JobDeps } from '../../src/jobs/deps.js';
import { createDocumentDeletedHandler, createDocumentUploadedHandler, createWorkspaceReembedHandler } from '../../src/jobs/documents.js';
import type { JobContext } from '../../src/jobs/types.js';
import { createSilentLogger } from '../../src/logger.js';
import { ragRoutes } from '../../src/rag/http/routes.js';
import { LocalAiApi } from '../../src/rag/localApi.js';
import { forgetKnownIndexes } from '../../src/rag/retrieval/indexes.js';
import type { RagService } from '../../src/rag/service.js';
import { announceSkip, testDatabaseUrl } from '../helpers/infra.js';
import { createRag, type FakeWeb, META, ragDatabase, signedHeaders, TEST_SECRET, type RagDatabase } from './support/harness.js';

const database = await ragDatabase();
const skip = database ? '' : announceSkip('Postgres (RAG HTTP API tests)', testDatabaseUrl());
const WORKER_SECRET = 'worker-ai-pair-secret-0123456789';

const POLICY = `# Bank details change procedure

## Stop

Pause the payment as soon as a vendor asks to change bank details.

## Call

Call the vendor on the number from the signed contract, never on the number in the e-mail.

## Confirm

Confirm the last four digits of the new account and who requested the change.
`;

function jobContext(overrides: Partial<JobContext> = {}): JobContext {
  return {
    queue: 'documents',
    name: 'document.uploaded',
    jobId: 'job-1',
    attempt: 1,
    maxAttempts: 5,
    isFinalAttempt: false,
    requestId: 'req-job-1',
    logger: createSilentLogger(),
    ...overrides,
  };
}

function envelope(topic: string, payload: unknown) {
  return { outbox_id: 'o-1', topic, workspace_id: 300, payload };
}

describe.skipIf(!database)(`/v1 HTTP contract${skip}`, () => {
  let app: FastifyInstance;
  let rag: RagService;
  let web: FakeWeb;

  async function call(method: 'GET' | 'POST' | 'PUT' | 'DELETE', url: string, payload?: unknown, service = 'web', secret = TEST_SECRET) {
    const body = payload === undefined ? '' : typeof payload === 'string' ? payload : JSON.stringify(payload);
    return app.inject({ method, url, payload: body === '' ? undefined : body, headers: signedHeaders(method, url, body, service, secret) });
  }

  beforeAll(async () => {
    ({ rag, web } = createRag(database!, { anthropicLister: async () => ['claude-opus-5-5'] }));
    await rag.migrate();
    app = Fastify();
    await app.register(ragRoutes({ rag, peerSecrets: { web: TEST_SECRET, worker: WORKER_SECRET } }));
    await app.ready();
  });

  afterAll(async () => {
    await app.close();
    await rag.flush();
    forgetKnownIndexes();
    await (database as RagDatabase).drop();
  });

  it('rejects unsigned, tampered, expired and mis-keyed requests', async () => {
    expect((await app.inject({ method: 'POST', url: '/v1/chat', payload: { a: 1 } })).statusCode).toBe(401);
    const body = JSON.stringify({ workspace_id: 1 });
    const headers = signedHeaders('POST', '/v1/search', body);
    expect((await app.inject({ method: 'POST', url: '/v1/search', payload: '{"workspace_id":2}', headers })).statusCode).toBe(401);
    expect((await call('GET', '/v1/admin/ai/settings', undefined, 'worker')).statusCode).toBe(401);
    expect((await call('GET', '/v1/admin/ai/settings', undefined, 'ai')).statusCode).toBe(401);
    expect((await call('GET', '/v1/admin/ai/settings', undefined, 'worker', WORKER_SECRET)).statusCode).toBe(200);
    const stale = { ...signedHeaders('GET', '/v1/admin/ai/settings'), 'x-signature': `t=${Math.floor(Date.now() / 1000) - 1000},v1=${'a'.repeat(64)}` };
    expect((await app.inject({ method: 'GET', url: '/v1/admin/ai/settings', headers: stale })).statusCode).toBe(401);
    const response = await app.inject({ method: 'GET', url: '/v1/admin/ai/usage?days=7', headers: signedHeaders('GET', '/v1/admin/ai/usage?days=1') });
    expect(response.statusCode).toBe(401);
    expect(response.json()).toEqual({ error: 'invalid_signature', detail: 'invalid_signature' });
  });

  it('ingests a document, reports status and previews chunks', async () => {
    web.addFile(31, POLICY, 'text/markdown', 'procedure.md');
    const response = await call('POST', '/v1/documents/31/ingest', {
      workspace_id: 300,
      title: 'Bank details change procedure',
      mime: 'text/markdown',
      metadata: { department: 'procedure', roles: ['owner', ' owner '], tags: ['lang:en', 'family:bank-details-change-procedure'], version: '2', effective_date: '2026-01-15' },
    });
    expect(response.statusCode).toBe(200);
    expect(response.json()).toMatchObject({ document_id: 31, ocr_used: false, embedding_model: 'hash:hash-384', status_reported: true });
    expect(response.json<{ chunks: number }>().chunks).toBeGreaterThanOrEqual(1);
    expect(web.statuses.filter((entry) => entry.documentId === 31).map((entry) => entry.payload.status)).toEqual(['processing', 'indexed']);
    const document = await rag.db.query<{ roles: string[]; effective_date: string; version: string }>(
      `SELECT roles, effective_date::text, version FROM ${rag.db.table('documents')} WHERE id = 31`,
    );
    expect(document.rows[0]).toEqual({ roles: ['owner'], effective_date: '2026-01-15', version: '2' });
    const preview = await call('GET', '/v1/documents/31/chunks?workspace_id=300&limit=2');
    expect(preview.statusCode).toBe(200);
    const chunks = preview.json<{ document_id: number; chunks: Array<{ section: string; content: string }> }>();
    expect(chunks.document_id).toBe(31);
    expect(chunks.chunks[0]!.section).toContain('Bank details change procedure');
    expect((await call('GET', '/v1/documents/31/chunks?workspace_id=999')).json<{ chunks: unknown[] }>().chunks).toEqual([]);
  });

  it('reports ingest failures and validates the body', async () => {
    web.addFile(32, Buffer.alloc(0), 'text/plain', 'empty.txt');
    const failed = await call('POST', '/v1/documents/32/ingest', { workspace_id: 300, title: 'Empty', mime: 'text/plain', metadata: META });
    expect(failed.statusCode).toBe(422);
    expect(failed.json()).toMatchObject({ error: 'ingest_failed', document_id: 32, detail: 'the file is empty' });
    expect(web.statuses.filter((entry) => entry.documentId === 32).at(-1)!.payload).toEqual({ status: 'failed', error: 'the file is empty' });
    const missing = await call('POST', '/v1/documents/33/ingest', { workspace_id: 300, title: 'Missing', mime: 'text/plain' });
    expect(missing.statusCode).toBe(422);
    const invalid = await call('POST', '/v1/documents/34/ingest', { workspace_id: 0, title: ' ' });
    expect(invalid.statusCode).toBe(422);
    expect(Object.keys(invalid.json<{ errors: Record<string, string[]> }>().errors).sort()).toEqual(['title', 'workspace_id']);
    expect((await call('POST', '/v1/documents/abc/ingest', { workspace_id: 1, title: 'x' })).statusCode).toBe(404);
    expect((await call('POST', '/v1/documents/35/ingest', '{not json')).statusCode).toBe(422);
  });

  it('answers /v1/chat with the contract shape and logs the query with the incoming request id', async () => {
    const body = JSON.stringify({
      workspace_id: 300,
      user: { id: 5, role: 'owner', locale: 'en', permissions: ['assistant.use'] },
      channel: 'web',
      conversation_id: 'web-5',
      message: 'Which number do I call when a vendor changes bank details?',
    });
    const headers = { ...signedHeaders('POST', '/v1/chat', body), 'x-request-id': 'req-http-chat-1' };
    const response = await app.inject({ method: 'POST', url: '/v1/chat', payload: body, headers });
    expect(response.statusCode).toBe(200);
    const output = response.json<Record<string, unknown>>();
    for (const key of ['answer_markdown', 'answer_html', 'citations', 'confidence', 'refused', 'tools_used', 'actions', 'provider', 'model', 'latency_ms']) {
      expect(output).toHaveProperty(key);
    }
    expect(output.refused).toBe(false);
    expect((output.citations as Array<{ document_id: number }>)[0]!.document_id).toBe(31);
    await rag.flush();
    expect(web.queries.at(-1)?.requestId).toBe('req-http-chat-1');
    const invalid = await call('POST', '/v1/chat', { workspace_id: 300, user: { role: 'owner' }, channel: 'fax', message: '   ' });
    expect(invalid.statusCode).toBe(422);
  });

  it('degrades chat to the offline provider when the configured provider has no key', async () => {
    expect((await call('PUT', '/v1/admin/ai/settings', { provider: 'anthropic' })).statusCode).toBe(200);
    const response = await call('POST', '/v1/chat', {
      workspace_id: 300,
      user: { id: 5, role: 'owner', locale: 'en', permissions: ['assistant.use'] },
      channel: 'web',
      message: 'Which number do I call when a vendor changes bank details?',
      ai_external_allowed: true,
    });
    expect(response.statusCode).toBe(200);
    expect(response.json()).toMatchObject({ degraded: true, provider: 'mock', model: 'mock-extractive-1', refused: false });
    expect((await call('PUT', '/v1/admin/ai/settings', { provider: 'mock' })).json()).toMatchObject({ provider: 'mock', model: 'mock-extractive-1', fallback_model: null });
  });

  it('searches with filters, deletes documents, re-embeds and purges a workspace', async () => {
    const search = await call('POST', '/v1/search', { workspace_id: 300, query: 'vendor contract number', k: 3, filters: { roles: ['member'] } });
    expect(search.statusCode).toBe(200);
    expect(search.json<{ results: unknown[] }>().results).toEqual([]);
    const privileged = await call('POST', '/v1/search', { workspace_id: 300, query: 'vendor contract number', k: 3 });
    expect(privileged.json<{ results: Array<{ document_id: number }>; embedding_model: string }>().results[0]!.document_id).toBe(31);
    const reembed = await call('POST', '/v1/workspaces/300/reembed', '[]');
    expect(reembed.statusCode).toBe(200);
    expect(reembed.json<{ chunks: number; embedding_model: string }>().embedding_model).toBe('hash:hash-384');
    expect((await call('DELETE', '/v1/documents/31?workspace_id=301')).statusCode).toBe(409);
    const removed = await call('DELETE', '/v1/documents/31?workspace_id=300');
    expect(removed.json<{ deleted_chunks: number }>().deleted_chunks).toBeGreaterThan(0);
    expect((await call('DELETE', '/v1/documents/31?workspace_id=300')).json()).toEqual({ document_id: 31, deleted_chunks: 0 });
    expect((await call('DELETE', '/v1/documents/31')).statusCode).toBe(422);
    const purge = await call('DELETE', '/v1/workspaces/300');
    expect(purge.statusCode).toBe(200);
    expect(purge.json<{ workspace_id: number; purged: { documents: number } }>().purged.documents).toBeGreaterThan(0);
    expect((await call('DELETE', '/v1/workspaces/300')).json()).toEqual({ workspace_id: 300, purged: { documents: 0, chunks: 0, conversation_turns: 0, usage_rows: 0 } });
    expect((await call('DELETE', '/v1/workspaces/0')).statusCode).toBe(404);
    expect(purge.json<{ purged: { usage_rows: number } }>().purged.usage_rows).toBeGreaterThan(0);
    const usage = await rag.db.query<{ n: number }>(`SELECT count(*)::int AS n FROM ${rag.db.table('ai_usage')} WHERE workspace_id = 300`);
    expect(usage.rows[0]!.n).toBe(0);
    const turns = await rag.db.query<{ n: number }>(`SELECT count(*)::int AS n FROM ${rag.db.table('conversation_turns')} WHERE workspace_id = 300`);
    expect(turns.rows[0]!.n).toBe(0);
  });

  it('lists providers with key hints and stores keys encrypted', async () => {
    const providers = (await call('GET', '/v1/admin/ai/providers?locale=ru')).json<Array<Record<string, unknown>>>();
    expect(providers.map((provider) => provider.id)).toEqual(['mock', 'anthropic', 'openai', 'deepseek', 'openrouter', 'nvidia', 'opencode-zen', 'opencode-go', 'ollama']);
    expect(providers[0]).toMatchObject({ configured: true, needs_key: false, key_source: null, key_hint: null, default_model: 'mock-extractive-1' });
    expect(String(providers[0]!.note)).toContain('Ключ и сеть не нужны');
    expect(providers[1]).toMatchObject({ id: 'anthropic', configured: false, models: ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-fable-5-1'] });
    const saved = await call('PUT', '/v1/admin/ai/keys/anthropic', { api_key: 'sk-ant-api-secret-9876' });
    expect(saved.json()).toEqual({ provider: 'anthropic', configured: true, key_source: 'settings', key_hint: '…9876' });
    const raw = await rag.db.query<{ value: unknown }>(`SELECT value FROM ${rag.db.table('settings')} WHERE key = 'provider_keys'`);
    expect(JSON.stringify(raw.rows[0]!.value)).not.toContain('sk-ant-api-secret');
    const models = await call('GET', '/v1/admin/ai/providers/anthropic/models');
    expect(models.json()).toMatchObject({ provider: 'anthropic', source: 'live', models: [{ id: 'claude-opus-5-5' }] });
    const cleared = await call('PUT', '/v1/admin/ai/keys/anthropic', { api_key: null });
    expect(cleared.json()).toMatchObject({ configured: false, key_source: null });
    expect((await call('PUT', '/v1/admin/ai/keys/ollama', { api_key: 'x' })).statusCode).toBe(422);
    expect((await call('PUT', '/v1/admin/ai/keys/nope', { api_key: 'x' })).statusCode).toBe(404);
    expect((await call('GET', '/v1/admin/ai/providers/nope/models')).statusCode).toBe(404);
    expect((await call('GET', '/v1/admin/ai/providers/mock/models')).json()).toMatchObject({ source: 'live', models: [{ id: 'mock-extractive-1', free: true }] });
  });

  it('validates and updates settings, flags re-embedding and pings the provider', async () => {
    const current = (await call('GET', '/v1/admin/ai/settings')).json<Record<string, unknown>>();
    expect(current).toMatchObject({ provider: 'mock', model: 'mock-extractive-1', fallback_model: null, embeddings: { provider: 'hash', model: 'hash-384' } });
    expect(current.embeddings_providers).toEqual(['local', 'openai-compatible', 'hash']);
    const invalid = await call('PUT', '/v1/admin/ai/settings', { provider: 'skynet' });
    expect(invalid.statusCode).toBe(422);
    expect(invalid.json<{ errors: Record<string, string[]> }>().errors).toHaveProperty('provider');
    expect((await call('PUT', '/v1/admin/ai/settings', { temperature: 3 })).statusCode).toBe(422);
    const switched = await call('PUT', '/v1/admin/ai/settings', { provider: 'anthropic', fallback_model: 'claude-haiku-5-5' });
    expect(switched.json()).toMatchObject({ provider: 'anthropic', model: 'claude-opus-5-5', fallback_model: 'claude-haiku-5-5', reembed_required: false });
    const failedTest = (await call('POST', '/v1/admin/ai/test', '[]')).json<Record<string, unknown>>();
    expect(failedTest).toMatchObject({ ok: false, provider: 'anthropic' });
    expect(String(failedTest.error)).toContain('not configured');
    const embeddings = await call('PUT', '/v1/admin/ai/settings', { provider: 'mock', embeddings: { provider: 'hash', model: 'hash-256' } });
    expect(embeddings.json()).toMatchObject({ provider: 'mock', model: 'mock-extractive-1', reembed_required: true, embeddings: { model: 'hash-256' } });
    const ping = (await call('POST', '/v1/admin/ai/test')).json<Record<string, unknown>>();
    expect(ping).toMatchObject({ ok: true, provider: 'mock', model: 'mock-extractive-1', reply: 'OK', error: null });
    await call('PUT', '/v1/admin/ai/settings', { embeddings: { provider: 'hash', model: 'hash-384' } });
  });

  it('reports usage by day, model and purpose', async () => {
    const response = await call('GET', '/v1/admin/ai/usage?days=7');
    expect(response.statusCode).toBe(200);
    const report = response.json<{ days: Array<{ date: string; requests: number }>; by_purpose: Array<{ purpose: string; requests: number }>; total_requests: number; errors: number }>();
    expect(report.days).toHaveLength(7);
    expect(report.days.at(-1)!.date).toBe(new Date().toISOString().slice(0, 10));
    expect(report.by_purpose.map((row) => row.purpose)).toEqual(['test']);
    expect(report.total_requests).toBeGreaterThanOrEqual(2);
    expect(report.errors).toBeGreaterThanOrEqual(1);
    expect((await call('GET', '/v1/admin/ai/usage?days=0')).statusCode).toBe(422);
  });

  it('serves the document jobs in-process through the local API', async () => {
    const ai = new LocalAiApi(rag);
    const reportDocumentStatus = vi.fn<WebApi['reportDocumentStatus']>(async () => undefined);
    const deps = { ai, web: { reportDocumentStatus } } as unknown as JobDeps;
    web.addFile(41, POLICY, 'text/markdown', 'p.md');
    const result = await createDocumentUploadedHandler(deps)(
      envelope('document.uploaded', { document_id: 41, workspace_id: 300, title: 'Procedure', mime: 'text/markdown', metadata: { roles: [], tags: ['lang:en'] } }),
      jobContext(),
    );
    expect(result).toMatchObject({ ocr_used: false });
    expect(web.statuses.filter((entry) => entry.documentId === 41).map((entry) => entry.requestId)).toEqual(['req-job-1', 'req-job-1']);
    await expect(createWorkspaceReembedHandler(deps)(envelope('workspace.reembed', { workspace_id: 300 }), jobContext())).resolves.toEqual({ reembedded: true });
    await expect(createDocumentDeletedHandler(deps)(envelope('document.deleted', { document_id: 41, workspace_id: 300 }), jobContext())).resolves.toEqual({ deleted: true });
    const left = await rag.db.query<{ n: number }>(`SELECT count(*)::int AS n FROM ${rag.db.table('chunks')} WHERE document_id = 41`);
    expect(left.rows[0]!.n).toBe(0);
    web.addFile(42, Buffer.alloc(0), 'text/plain', 'e.txt');
    await expect(
      createDocumentUploadedHandler(deps)(envelope('document.uploaded', { document_id: 42, workspace_id: 300, title: 'Empty' }), jobContext()),
    ).rejects.toBeInstanceOf(UnrecoverableError);
    await expect(ai.deleteDocument('8f9a4a0e-2a54-4e8e-bb0b-0d3c2f1e9a11', 300)).rejects.toBeInstanceOf(UnrecoverableError);
    const chat = await ai.chat({ workspace_id: 300, user: { id: 1, role: 'owner', locale: 'ru', permissions: [] }, channel: 'telegram', conversation_id: 'tg-1', message: 'Какая погода?' }, 'req-tg');
    expect(chat.refused).toBe(true);
    expect(chat.actions[0]!.type).toBe('escalate');
  });
});
