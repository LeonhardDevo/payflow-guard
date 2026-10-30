import { randomUUID } from 'node:crypto';
import { setTimeout as sleep } from 'node:timers/promises';
import { UpstreamHttpError, UpstreamNetworkError, isRetryableError } from '../../clients/errors.js';
import { internalFetch, type InternalTarget, type QueryValue } from '../../clients/internalFetch.js';
import { REQUEST_ID_HEADER, SERVICE_HEADER, SIGNATURE_HEADER, signRequest } from '../../clients/signing.js';

export const WEB_READ_PATHS: readonly RegExp[] = [/^\/internal\/v1\/documents\/\d+\/file$/, /^\/internal\/v1\/payflow\/ai\/[a-z0-9][a-z0-9/_-]*$/];
export const WEB_WRITE_PATHS: readonly RegExp[] = [/^\/internal\/v1\/documents\/\d+\/status$/, /^\/internal\/v1\/ai-queries$/];

export class WebPathNotAllowedError extends Error {
  constructor(method: string, path: string) {
    super(`${method} ${path} is not reachable from the RAG service`);
    this.name = 'WebPathNotAllowedError';
  }
}

export function assertWebPathAllowed(method: 'GET' | 'POST', path: string): void {
  const list = method === 'GET' ? WEB_READ_PATHS : WEB_WRITE_PATHS;
  if (path.includes('..') || !list.some((pattern) => pattern.test(path))) throw new WebPathNotAllowedError(method, path);
}

export class FileTooLargeError extends Error {
  readonly retryable = false;
  readonly status = 413;

  constructor(maxBytes: number) {
    super(`file exceeds ${Math.floor(maxBytes / (1024 * 1024))} MB`);
    this.name = 'FileTooLargeError';
  }
}

export interface DownloadedFile {
  content: Buffer;
  contentType: string | null;
  filename: string | null;
}

export interface DocumentStatusPayload {
  status: 'processing' | 'indexed' | 'failed';
  pages?: number | null;
  chunks?: number;
  ocr_used?: boolean;
  error?: string;
}

export interface AiQueryPayload {
  workspace_id: number;
  user_id: number | null;
  channel: 'telegram' | 'web';
  conversation_id: string | null;
  question: string;
  answer: string;
  refused: boolean;
  confidence: number;
  citations: Array<{ document_id: number; title: string; page: number | null }>;
  tools_used: string[];
  latency_ms: number;
  provider: string;
  model: string;
}

export interface RagWebApi {
  downloadDocument(documentId: number, requestId?: string): Promise<DownloadedFile>;
  reportDocumentStatus(documentId: number, payload: DocumentStatusPayload, requestId?: string): Promise<void>;
  logAiQuery(payload: AiQueryPayload, requestId?: string): Promise<void>;
  getJson<T = unknown>(path: string, query?: Record<string, QueryValue>, requestId?: string): Promise<T>;
  postJson<T = unknown>(path: string, body: unknown, requestId?: string): Promise<T>;
}

export function filenameFromDisposition(value: string | null): string | null {
  if (!value) return null;
  let plain: string | null = null;
  for (const part of value.split(';')) {
    const [rawKey, ...rest] = part.trim().split('=');
    const key = (rawKey ?? '').toLowerCase();
    const raw = rest.join('=');
    if (key === 'filename*' && raw.includes("''")) {
      try {
        return decodeURIComponent(raw.split("''", 2)[1] ?? '');
      } catch {
        continue;
      }
    }
    if (key === 'filename') plain = raw.replace(/^"|"$/g, '');
  }
  return plain;
}

async function withRetry<T>(fn: () => Promise<T>, attempts = 3): Promise<T> {
  for (let attempt = 1; ; attempt++) {
    try {
      return await fn();
    } catch (error) {
      if (attempt >= attempts || !isRetryableError(error)) throw error;
      await sleep(Math.min(4000, 500 * 2 ** (attempt - 1)));
    }
  }
}

export class RagWebClient implements RagWebApi {
  constructor(
    private readonly target: InternalTarget,
    private readonly maxFileBytes: number,
  ) {}

  async downloadDocument(documentId: number, requestId?: string): Promise<DownloadedFile> {
    return withRetry(() => this.download(documentId, requestId));
  }

  private async download(documentId: number, requestId?: string): Promise<DownloadedFile> {
    const path = `/internal/v1/documents/${documentId}/file`;
    assertWebPathAllowed('GET', path);
    const headers: Record<string, string> = {
      Accept: '*/*',
      [SERVICE_HEADER]: this.target.caller ?? 'ai',
      [REQUEST_ID_HEADER]: requestId ?? randomUUID(),
      [SIGNATURE_HEADER]: signRequest({ secret: this.target.secret, method: 'GET', pathWithQuery: path, body: '' }),
    };
    let response: Response;
    try {
      response = await (this.target.fetch ?? fetch)(`${this.target.baseUrl}${path}`, {
        method: 'GET',
        headers,
        signal: AbortSignal.timeout(Math.max(this.target.timeoutMs, 60_000)),
      });
    } catch (error) {
      throw new UpstreamNetworkError('web', 'GET', path, error);
    }
    if (!response.ok) {
      const body = await response.text().catch(() => '');
      if (response.status === 413) throw new FileTooLargeError(this.maxFileBytes);
      throw new UpstreamHttpError('web', 'GET', path, response.status, body.slice(0, 300));
    }
    const declared = Number(response.headers.get('content-length'));
    if (Number.isFinite(declared) && declared > this.maxFileBytes) {
      await response.body?.cancel().catch(() => undefined);
      throw new FileTooLargeError(this.maxFileBytes);
    }
    const parts: Buffer[] = [];
    let total = 0;
    if (response.body) {
      for await (const chunk of response.body as unknown as AsyncIterable<Uint8Array>) {
        total += chunk.byteLength;
        if (total > this.maxFileBytes) {
          await response.body.cancel().catch(() => undefined);
          throw new FileTooLargeError(this.maxFileBytes);
        }
        parts.push(Buffer.from(chunk));
      }
    }
    return {
      content: Buffer.concat(parts),
      contentType: response.headers.get('content-type'),
      filename: filenameFromDisposition(response.headers.get('content-disposition')),
    };
  }

  async reportDocumentStatus(documentId: number, payload: DocumentStatusPayload, requestId?: string): Promise<void> {
    await this.postJson(`/internal/v1/documents/${documentId}/status`, payload, requestId, true);
  }

  async logAiQuery(payload: AiQueryPayload, requestId?: string): Promise<void> {
    await this.postJson('/internal/v1/ai-queries', payload, requestId);
  }

  async getJson<T = unknown>(path: string, query?: Record<string, QueryValue>, requestId?: string): Promise<T> {
    assertWebPathAllowed('GET', path);
    const response = await internalFetch<T>(this.target, { method: 'GET', path, query, requestId });
    return response.data;
  }

  async postJson<T = unknown>(path: string, body: unknown, requestId?: string, retry = false): Promise<T> {
    assertWebPathAllowed('POST', path);
    const call = async (): Promise<T> => (await internalFetch<T>(this.target, { method: 'POST', path, body, requestId })).data;
    return retry ? withRetry(call) : call();
  }
}
