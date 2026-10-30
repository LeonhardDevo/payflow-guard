const LOCAL_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]', '::1', '0.0.0.0']);
const NON_NETWORK = new Set(['data:', 'blob:', 'about:', 'javascript:', 'chrome:', 'chrome-extension:', 'devtools:']);

export function isLocalHost(hostname: string, extraAllowed: readonly string[] = []): boolean {
  const host = hostname.toLowerCase();
  if (LOCAL_HOSTS.has(host)) return true;
  if (host.endsWith('.localhost')) return true;
  if (/^127(?:\.\d{1,3}){3}$/.test(host)) return true;
  return extraAllowed.includes(host);
}

export function isExternalRequest(url: string, extraAllowed: readonly string[] = []): boolean {
  let parsed: URL;
  try {
    parsed = new URL(url);
  } catch {
    return false;
  }
  if (NON_NETWORK.has(parsed.protocol)) return false;
  return !isLocalHost(parsed.hostname, extraAllowed);
}

export interface ExternalRequest {
  url: string;
  resourceType: string;
  frameUrl: string | null;
}

export function formatViolations(requests: readonly ExternalRequest[]): string {
  const unique = new Map<string, ExternalRequest>();
  for (const request of requests) if (!unique.has(request.url)) unique.set(request.url, request);
  const lines = [...unique.values()].map(
    (request) => `  - [${request.resourceType}] ${request.url}${request.frameUrl ? `  (from ${request.frameUrl})` : ''}`,
  );
  return `The page tried to reach ${unique.size} non-localhost URL(s); everything must be bundled locally (no CDN):\n${lines.join('\n')}`;
}
