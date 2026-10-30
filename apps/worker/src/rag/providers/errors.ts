export function statusOf(error: { status?: unknown }): number | null {
  return typeof error.status === 'number' ? error.status : null;
}

export function headersOf(error: { headers?: unknown }): { get(name: string): string | null } | null {
  const headers = error.headers;
  if (headers && typeof headers === 'object' && 'get' in headers && typeof headers.get === 'function') {
    return headers as { get(name: string): string | null };
  }
  return null;
}
