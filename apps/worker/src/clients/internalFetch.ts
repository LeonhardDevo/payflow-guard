import { randomUUID } from 'node:crypto';
import { REQUEST_ID_HEADER, SERVICE_HEADER, SIGNATURE_HEADER, signRequest, type ServiceName } from './signing.js';
import { UpstreamHttpError, UpstreamNetworkError } from './errors.js';

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
export type QueryValue = string | number | boolean | null | undefined;

export interface InternalTarget {
  service: ServiceName;
  baseUrl: string;
  secret: string;
  timeoutMs: number;
  fetch?: typeof fetch;
  caller?: ServiceName;
}

export interface InternalRequest {
  method: HttpMethod;
  path: string;
  query?: Record<string, QueryValue>;
  body?: unknown;
  requestId?: string;
  timeoutMs?: number;
  signal?: AbortSignal;
}

export interface InternalResponse<T> {
  status: number;
  data: T;
  requestId: string;
}

export async function internalFetch<T = unknown>(target: InternalTarget, request: InternalRequest): Promise<InternalResponse<T>> {
  const method = request.method;
  const pathWithQuery = buildPathWithQuery(request.path, request.query);
  const rawBody = request.body === undefined ? '' : JSON.stringify(request.body);
  const requestId = request.requestId ?? randomUUID();
  const url = `${target.baseUrl}${pathWithQuery}`;

  const headers: Record<string, string> = {
    Accept: 'application/json',
    [SERVICE_HEADER]: target.caller ?? 'worker',
    [REQUEST_ID_HEADER]: requestId,
    [SIGNATURE_HEADER]: signRequest({ secret: target.secret, method, pathWithQuery, body: rawBody }),
  };
  if (rawBody !== '') headers['Content-Type'] = 'application/json';

  const timeout = AbortSignal.timeout(request.timeoutMs ?? target.timeoutMs);
  const signal = request.signal ? AbortSignal.any([timeout, request.signal]) : timeout;
  const doFetch = target.fetch ?? fetch;

  let response: Response;
  try {
    response = await doFetch(url, { method, headers, body: rawBody === '' ? undefined : rawBody, signal });
  } catch (error) {
    throw new UpstreamNetworkError(target.service, method, request.path, error);
  }

  const data = await readBody(response);
  if (!response.ok) {
    throw new UpstreamHttpError(target.service, method, request.path, response.status, data);
  }
  return { status: response.status, data: data as T, requestId };
}

export function buildPathWithQuery(path: string, query?: Record<string, QueryValue>): string {
  if (!path.startsWith('/')) throw new Error(`Internal path must start with "/": ${path}`);
  if (!query) return path;
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    if (value === undefined || value === null) continue;
    params.append(key, String(value));
  }
  const qs = params.toString();
  return qs === '' ? path : `${path}?${qs}`;
}

async function readBody(response: Response): Promise<unknown> {
  const text = await response.text().catch(() => '');
  if (text === '') return null;
  const contentType = response.headers.get('content-type') ?? '';
  if (contentType.includes('json')) {
    try {
      return JSON.parse(text) as unknown;
    } catch {
      return text;
    }
  }
  return text;
}
