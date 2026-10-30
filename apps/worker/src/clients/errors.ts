export class UpstreamHttpError extends Error {
  constructor(
    public readonly service: string,
    public readonly method: string,
    public readonly path: string,
    public readonly status: number,
    public readonly body: unknown,
  ) {
    super(`${service} ${method} ${path} responded ${status}${describeBody(body)}`);
    this.name = 'UpstreamHttpError';
  }

  get retryable(): boolean {
    return isRetryableStatus(this.status);
  }
}

export class UpstreamNetworkError extends Error {
  readonly retryable = true;

  constructor(
    public readonly service: string,
    public readonly method: string,
    public readonly path: string,
    cause: unknown,
  ) {
    const reason = cause instanceof Error ? cause.message : String(cause);
    super(`${service} ${method} ${path} failed: ${reason}`, { cause });
    this.name = 'UpstreamNetworkError';
  }
}

export function isRetryableStatus(status: number): boolean {
  return status === 408 || status === 425 || status === 429 || status >= 500;
}

export function isRetryableError(error: unknown): boolean {
  if (error instanceof UpstreamHttpError) return error.retryable;
  if (error instanceof UpstreamNetworkError) return true;
  const retryable = (error as { retryable?: unknown } | null)?.retryable;
  if (typeof retryable === 'boolean') return retryable;
  return true;
}

export function isNotFound(error: unknown): boolean {
  return error instanceof UpstreamHttpError && (error.status === 404 || error.status === 410);
}

function describeBody(body: unknown): string {
  if (body === undefined || body === null || body === '') return '';
  let message: string;
  if (typeof body === 'string') message = body;
  else if (typeof body === 'object' && 'message' in body && typeof body.message === 'string') message = body.message;
  else message = JSON.stringify(body);
  return `: ${message.slice(0, 300)}`;
}
