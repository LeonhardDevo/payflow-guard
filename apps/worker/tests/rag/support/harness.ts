import { randomUUID } from 'node:crypto';
import pg from 'pg';
import { UpstreamHttpError } from '../../../src/clients/errors.js';
import { signRequest } from '../../../src/clients/signing.js';
import '../../../src/db/pool.js';
import { createSilentLogger } from '../../../src/logger.js';
import { parseRagConfig, type RagConfig } from '../../../src/rag/config.js';
import type { OcrEngine } from '../../../src/rag/ingest/ocr.js';
import { RagService, type RagServiceOptions } from '../../../src/rag/service.js';
import type { AiQueryPayload, DocumentStatusPayload, DownloadedFile, RagWebApi } from '../../../src/rag/web/client.js';
import { fileEnvValue, postgresAvailable, uniqueSuffix } from '../../helpers/infra.js';

export const TEST_SECRET = 'rag-test-secret-0123456789abcdef';

export function ragConfig(overrides: Record<string, string> = {}): RagConfig {
  const tessdata = fileEnvValue('TESSDATA_PREFIX');
  const result = parseRagConfig(
    {
      AI_PROVIDER: 'mock',
      EMBEDDINGS_PROVIDER: 'hash',
      AI_SETTINGS_KEY: 'test-settings-key-0123456789abcdef',
      AI_RETRY_BASE_MS: '1',
      AI_RETRY_MAX_MS: '5',
      ...(tessdata ? { TESSDATA_PREFIX: tessdata } : {}),
      ...overrides,
    },
    false,
  );
  if (!result.ok) throw new Error(result.issues.join('; '));
  return result.config;
}

export class FakeWeb implements RagWebApi {
  readonly files = new Map<number, DownloadedFile>();
  readonly statuses: Array<{ documentId: number; payload: DocumentStatusPayload; requestId: string | undefined }> = [];
  readonly queries: Array<{ payload: AiQueryPayload; requestId: string | undefined }> = [];
  failQueryLog = false;

  addFile(documentId: number, content: Buffer | string, contentType: string, filename: string): void {
    this.files.set(documentId, { content: Buffer.isBuffer(content) ? content : Buffer.from(content, 'utf8'), contentType, filename });
  }

  async downloadDocument(documentId: number): Promise<DownloadedFile> {
    const file = this.files.get(documentId);
    if (!file) throw new UpstreamHttpError('web', 'GET', `/internal/v1/documents/${documentId}/file`, 404, null);
    return file;
  }

  async reportDocumentStatus(documentId: number, payload: DocumentStatusPayload, requestId?: string): Promise<void> {
    this.statuses.push({ documentId, payload, requestId });
  }

  async logAiQuery(payload: AiQueryPayload, requestId?: string): Promise<void> {
    if (this.failQueryLog) throw new Error('web is down');
    this.queries.push({ payload, requestId });
  }

  async getJson<T>(_path?: string, _query?: Record<string, unknown>): Promise<T> {
    return {} as T;
  }

  async postJson<T>(_path?: string, _body?: unknown): Promise<T> {
    return {} as T;
  }
}

export const noOcr: OcrEngine = {
  available: async () => false,
  missingLanguages: async () => ['rus', 'eng'],
  languages: async () => {
    throw new Error('no ocr');
  },
  imageToText: async () => {
    throw new Error('no ocr');
  },
};

export interface RagDatabase {
  url: string;
  pool: pg.Pool;
  schema: string;
  drop(): Promise<void>;
}

export async function ragDatabase(): Promise<RagDatabase | null> {
  const url = await postgresAvailable();
  if (!url) return null;
  const pool = new pg.Pool({ connectionString: url, max: 6 });
  const schema = `rag_t_${uniqueSuffix()}`;
  return {
    url,
    pool,
    schema,
    async drop() {
      await pool.query(`DROP SCHEMA IF EXISTS "${schema}" CASCADE`).catch(() => undefined);
      await pool.end();
    },
  };
}

export function createRag(
  database: RagDatabase,
  options: Partial<RagServiceOptions> & { env?: Record<string, string> } = {},
): { rag: RagService; web: FakeWeb } {
  const web = options.web instanceof FakeWeb ? options.web : new FakeWeb();
  const { env, ...rest } = options;
  const rag = new RagService({
    config: ragConfig({ RAG_SCHEMA: database.schema, ...(env ?? {}) }),
    productName: 'PayFlow Guard',
    publicWebUrl: 'http://app.test',
    webUrl: 'http://web.test',
    webAiKey: TEST_SECRET,
    sharedSecret: TEST_SECRET,
    isProduction: false,
    webTimeoutMs: 5000,
    pool: database.pool,
    logger: createSilentLogger(),
    ocr: noOcr,
    ...rest,
    web,
  });
  return { rag, web };
}

export function signedHeaders(method: string, pathWithQuery: string, body = '', service = 'web', secret = TEST_SECRET): Record<string, string> {
  return {
    'content-type': 'application/json',
    'x-service': service,
    'x-request-id': randomUUID(),
    'x-signature': signRequest({ secret, method, pathWithQuery, body }),
  };
}

export const META = { department: null, roles: [] as string[], tags: [] as string[], version: null, effective_date: null };
