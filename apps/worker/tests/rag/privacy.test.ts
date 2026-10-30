import Fastify, { type FastifyInstance } from 'fastify';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { createSilentLogger } from '../../src/logger.js';
import { SecretBox, SecretBoxError } from '../../src/rag/crypto.js';
import { HashEmbedder } from '../../src/rag/embeddings/hash.js';
import { EmbeddingsRegistry } from '../../src/rag/embeddings/registry.js';
import { RemoteEmbedder } from '../../src/rag/embeddings/remote.js';
import { ragHttp } from '../../src/rag/http/index.js';
import { LocalAiApi } from '../../src/rag/localApi.js';
import { ProviderKeys } from '../../src/rag/providers/keys.js';
import { forgetKnownIndexes } from '../../src/rag/retrieval/indexes.js';
import type { RagService } from '../../src/rag/service.js';
import { AiSettingsService, MemorySettingsStore } from '../../src/rag/settings/store.js';
import { assertWebPathAllowed, WebPathNotAllowedError } from '../../src/rag/web/client.js';
import { announceSkip, testDatabaseUrl } from '../helpers/infra.js';
import { createRag, type FakeWeb, ragConfig, ragDatabase, signedHeaders, TEST_SECRET, type RagDatabase } from './support/harness.js';

const database = await ragDatabase();
const skip = database ? '' : announceSkip('Postgres (RAG privacy tests)', testDatabaseUrl());
const WORKER_SECRET = 'worker-ai-pair-secret-0123456789';
const POLICY_MD = '# Leave policy\n\nEmployees get 28 calendar days of paid vacation per year.\n';
const REMOTE_ENV = {
  EMBEDDINGS_PROVIDER: 'openai-compatible',
  EMBEDDINGS_MODEL: 'text-embedding-3-small',
  EMBEDDINGS_BASE_URL: 'https://embeddings.invalid/v1',
  EMBEDDINGS_API_KEY: 'sk-test',
};

function remoteCalls(): { calls: string[]; fetch: typeof fetch } {
  const calls: string[] = [];
  const fetchImpl = (async (url: string | URL | Request) => {
    calls.push(url instanceof Request ? url.url : url.toString());
    throw new Error('remote embeddings must not be called');
  }) as typeof fetch;
  return { calls, fetch: fetchImpl };
}

function remoteRegistry(fetchImpl: typeof fetch): EmbeddingsRegistry {
  const config = ragConfig(REMOTE_ENV);
  const store = new MemorySettingsStore();
  const settings = new AiSettingsService(store, config);
  const logger = createSilentLogger();
  return new EmbeddingsRegistry(config, settings, new ProviderKeys(store, config.providerKeys, new SecretBox('k'), logger), logger, { fetch: fetchImpl });
}

describe('embeddings consent', () => {
  it('uses an on-device embedder when the workspace does not allow external providers', async () => {
    const remote = remoteCalls();
    const registry = remoteRegistry(remote.fetch);
    expect(await registry.isExternal()).toBe(true);
    const local = await registry.active(undefined, { externalAllowed: false });
    expect(local).toBeInstanceOf(HashEmbedder);
    await local.embedDocuments(['Ivan Petrov, salary 100 000']);
    await local.embedQuery('salary of Ivan');
    expect(await registry.active(undefined, { externalAllowed: true })).toBeInstanceOf(RemoteEmbedder);
    expect(remote.calls).toEqual([]);
  });
});

describe('stored provider keys', () => {
  it('reads keys saved in the earlier Fernet format', () => {
    const derived = new SecretBox('dev-derived::rag-test-secret-0123456789abcdef');
    expect(derived.decrypt('gAAAAABq2pykdjzPJnQEn70G-EQLI7QfRvF2rqN92DMZ11-zzaJsMoT6a4ZpeYxyejNUb942xDSroJzoCqKrJUy3mstxHCnA5w==')).toBe('sk-legacy-1234');
    const raw = new SecretBox('wj53FDuGi2tNNwTXkXlNJbrL3040duv-x8CqyyQ4Nho=');
    expect(raw.decrypt('gAAAAABq2pyk5OnS4GtXV0M6VPN5t-P67INyXn0XT5F9W2Odp7piVed80zin10-ZHJb78kVG9zP6AG92htEqokx-J9VFXnLXdQ==')).toBe('sk-raw-key-5678');
    expect(() => derived.decrypt('gAAAAABq2pyk5OnS4GtXV0M6VPN5t-P67INyXn0XT5F9W2Odp7piVed80zin10-ZHJb78kVG9zP6AG92htEqokx-J9VFXnLXdQ==')).toThrow(SecretBoxError);
    const token = raw.encrypt('sk-new');
    expect(token.startsWith('v1:')).toBe(true);
    expect(raw.decrypt(token)).toBe('sk-new');
  });
});

describe('web paths reachable from the RAG service', () => {
  it('allows only document, query-log and read-only domain endpoints', () => {
    expect(() => assertWebPathAllowed('GET', '/internal/v1/documents/12/file')).not.toThrow();
    expect(() => assertWebPathAllowed('GET', '/internal/v1/payflow/ai/invoices/overdue')).not.toThrow();
    expect(() => assertWebPathAllowed('POST', '/internal/v1/documents/12/status')).not.toThrow();
    expect(() => assertWebPathAllowed('POST', '/internal/v1/ai-queries')).not.toThrow();
    for (const [method, path] of [
      ['POST', '/internal/v1/payflow/ai/invoices/overdue'],
      ['POST', '/internal/v1/payflow/bot/payouts/1/decision'],
      ['GET', '/internal/v1/telegram/context'],
      ['GET', '/internal/v1/payflow/ai/../bot/views/overdue'],
      ['POST', '/internal/v1/stripe/events'],
    ] as const) {
      expect(() => assertWebPathAllowed(method, path)).toThrow(WebPathNotAllowedError);
    }
  });
});

describe.skipIf(!database)(`privacy endpoints${skip}`, () => {
  let app: FastifyInstance;
  let rag: RagService;
  let web: FakeWeb;

  async function call(method: 'GET' | 'POST' | 'PUT' | 'DELETE', url: string, payload?: unknown) {
    const body = payload === undefined ? '' : JSON.stringify(payload);
    return app.inject({ method, url, payload: body === '' ? undefined : body, headers: signedHeaders(method, url, body) });
  }

  async function chat(workspaceId: number, extra: Record<string, unknown> = {}): Promise<Record<string, unknown>> {
    const response = await call('POST', '/v1/chat', {
      workspace_id: workspaceId,
      user: { id: 5, role: 'member', locale: 'en', permissions: [] },
      channel: 'web',
      conversation_id: 'conv-privacy',
      message: 'How many vacation days do employees get?',
      ...extra,
    });
    expect(response.statusCode).toBe(200);
    return response.json<Record<string, unknown>>();
  }

  async function ingest(documentId: number, workspaceId: number): Promise<void> {
    web.addFile(documentId, POLICY_MD, 'text/markdown', `doc-${documentId}.md`);
    const response = await call('POST', `/v1/documents/${documentId}/ingest`, { workspace_id: workspaceId, title: 'Leave policy', mime: 'text/markdown', metadata: {} });
    expect(response.statusCode).toBe(200);
  }

  async function turn(workspaceId: number, userId: number, ageDays: number): Promise<void> {
    await rag.db.query(
      `INSERT INTO ${rag.db.table('conversation_turns')} (workspace_id, conversation_id, user_id, role, content, created_at)
       VALUES ($1, 'c', $2, 'user', 'question', now() - make_interval(days => $3::int))`,
      [workspaceId, userId, ageDays],
    );
  }

  async function turns(where: string): Promise<number> {
    const { rows } = await rag.db.query<{ n: number }>(`SELECT count(*)::int AS n FROM ${rag.db.table('conversation_turns')} WHERE ${where}`);
    return rows[0]!.n;
  }

  beforeAll(async () => {
    ({ rag, web } = createRag(database!));
    await rag.migrate();
    app = Fastify();
    await app.register(ragHttp({ rag, peerSecrets: { web: TEST_SECRET, worker: WORKER_SECRET } }));
    await app.ready();
  });

  beforeEach(async () => {
    await rag.db.query(`TRUNCATE ${rag.db.table('conversation_turns')}, ${rag.db.table('ai_usage')}`);
    await rag.settings.update({ provider: 'mock', model: 'mock-extractive-1' });
  });

  afterAll(async () => {
    await app.close();
    await rag.flush();
    forgetKnownIndexes();
    await (database as RagDatabase).drop();
  });

  it('answers locally when the workspace has not allowed external providers', async () => {
    await ingest(1, 100);
    await rag.settings.update({ provider: 'anthropic' });
    const blocked = await chat(100);
    expect(blocked).toMatchObject({ provider: 'mock', external_blocked: true, degraded: true, refused: false });
    const explicit = await chat(100, { ai_external_allowed: false });
    expect(explicit).toMatchObject({ provider: 'mock', external_blocked: true });
    const allowed = await chat(100, { ai_external_allowed: true });
    expect(allowed).toMatchObject({ external_blocked: false, degraded: true });
  });

  it('purges every row of a workspace and nothing of another one', async () => {
    await ingest(11, 100);
    await ingest(12, 200);
    await chat(100);
    await chat(200);
    const response = await call('DELETE', '/v1/workspaces/100');
    expect(response.statusCode).toBe(200);
    const purged = response.json<{ purged: Record<string, number> }>().purged;
    expect(purged.documents).toBeGreaterThanOrEqual(1);
    expect(purged.chunks).toBeGreaterThanOrEqual(1);
    expect(purged.conversation_turns).toBeGreaterThanOrEqual(2);
    for (const table of ['documents', 'chunks', 'conversation_turns', 'ai_usage'] as const) {
      const { rows } = await rag.db.query<{ n: number }>(`SELECT count(*)::int AS n FROM ${rag.db.table(table)} WHERE workspace_id = 100`);
      expect(rows[0]!.n, table).toBe(0);
    }
    const other = await rag.db.query<{ n: number }>(`SELECT count(*)::int AS n FROM ${rag.db.table('chunks')} WHERE workspace_id = 200`);
    expect(other.rows[0]!.n).toBeGreaterThanOrEqual(1);
    expect((await call('DELETE', '/v1/workspaces/100')).json<{ purged: Record<string, number> }>().purged.documents).toBe(0);
    expect((await call('DELETE', '/v1/workspaces/0')).statusCode).toBe(404);
  });

  it('erases one user conversation memory only', async () => {
    await turn(100, 7, 0);
    await turn(200, 7, 1);
    await turn(100, 8, 0);
    const response = await call('DELETE', '/v1/users/7');
    expect(response.statusCode).toBe(200);
    expect(response.json()).toEqual({ user_id: 7, purged: { conversation_turns: 2 } });
    expect(await turns('user_id = 7')).toBe(0);
    expect(await turns('user_id = 8')).toBe(1);
    expect((await call('DELETE', '/v1/users/7')).json()).toEqual({ user_id: 7, purged: { conversation_turns: 0 } });
    expect((await call('DELETE', '/v1/users/0')).statusCode).toBe(404);
  });

  it('prunes conversation memory per workspace retention, capped at 30 days', async () => {
    for (const workspaceId of [100, 200, 300]) {
      for (const age of [1, 10, 40]) await turn(workspaceId, 5, age);
    }
    const response = await call('POST', '/v1/privacy/prune-memory', {
      default_days: 180,
      workspaces: [
        { workspace_id: 200, days: 7 },
        { workspace_id: 300, days: 365 },
      ],
    });
    expect(response.statusCode).toBe(200);
    expect(response.json()).toEqual({ pruned: 4 });
    expect(await turns('workspace_id = 100')).toBe(2);
    expect(await turns('workspace_id = 200')).toBe(1);
    expect(await turns('workspace_id = 300')).toBe(2);
    expect((await call('POST', '/v1/privacy/prune-memory', { default_days: 0 })).statusCode).toBe(422);
  });

  it('serves the privacy jobs in-process through the local API', async () => {
    const ai = new LocalAiApi(rag);
    await turn(400, 9, 0);
    await turn(400, 9, 60);
    await ai.pruneMemory({ default_days: 180, workspaces: [] });
    expect(await turns('workspace_id = 400')).toBe(1);
    await ai.purgeUser(9);
    expect(await turns('user_id = 9')).toBe(0);
    await ingest(21, 400);
    await ai.purgeWorkspace(400);
    const { rows } = await rag.db.query<{ n: number }>(`SELECT count(*)::int AS n FROM ${rag.db.table('documents')} WHERE workspace_id = 400`);
    expect(rows[0]!.n).toBe(0);
    await expect(ai.purgeUser(0)).rejects.toThrow(/positive integer/);
  });
});

describe.skipIf(!database)(`ingestion consent${skip}`, () => {
  let rag: RagService;
  let web: FakeWeb;
  let remote: ReturnType<typeof remoteCalls>;
  let db: RagDatabase;

  beforeAll(async () => {
    db = (await ragDatabase())!;
    remote = remoteCalls();
    ({ rag, web } = createRag(db, { env: REMOTE_ENV, fetch: remote.fetch }));
    await rag.migrate();
  });

  afterAll(async () => {
    await rag.flush();
    forgetKnownIndexes();
    await db.drop();
  });

  it('never sends documents or questions to remote embeddings without consent', async () => {
    web.addFile(1, POLICY_MD, 'text/markdown', 'policy.md');
    const outcome = await rag.ingest(1, {
      workspace_id: 100,
      title: 'Leave policy',
      mime: 'text/markdown',
      metadata: { department: null, roles: [], tags: [], version: null, effective_date: null },
    });
    expect(outcome.embedding_model).toBe('hash:hash-384');
    expect(outcome.warnings.some((warning) => warning.includes('external embeddings not allowed'))).toBe(true);
    const answer = await rag.chat({
      workspace_id: 100,
      user: { id: 5, role: 'member', locale: 'en', permissions: [] },
      channel: 'web',
      message: 'How many vacation days do employees get? Reply to ivan@example.com',
    });
    expect(answer.refused).toBe(false);
    const reembed = await rag.reembedWorkspace(100, false);
    expect(reembed).toMatchObject({ embedding_model: 'hash:hash-384' });
    expect(remote.calls).toEqual([]);
  });
});
