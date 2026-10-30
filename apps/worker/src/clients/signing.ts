import { createHmac, timingSafeEqual } from 'node:crypto';

export const SIGNATURE_HEADER = 'X-Signature';
export const SERVICE_HEADER = 'X-Service';
export const REQUEST_ID_HEADER = 'X-Request-Id';
export const MAX_CLOCK_SKEW_S = 300;

export type ServiceName = 'web' | 'worker' | 'ai';

export interface SignInput {
  secret: string;
  method: string;
  pathWithQuery: string;
  body: string;
  timestamp?: number;
}

export function computeSignature(input: Required<SignInput>): string {
  const base = `${input.timestamp}.${input.method.toUpperCase()}.${input.pathWithQuery}.${input.body}`;
  return createHmac('sha256', input.secret).update(base, 'utf8').digest('hex');
}

export function signRequest(input: SignInput): string {
  const timestamp = input.timestamp ?? Math.floor(Date.now() / 1000);
  const hex = computeSignature({ ...input, timestamp });
  return `t=${timestamp},v1=${hex}`;
}

export interface ParsedSignature {
  timestamp: number;
  signatures: string[];
}

export function parseSignatureHeader(header: string | undefined | null): ParsedSignature | null {
  if (!header) return null;
  let timestamp: number | null = null;
  const signatures: string[] = [];
  for (const part of header.split(',')) {
    const [rawKey, ...rest] = part.split('=');
    const key = rawKey?.trim();
    const value = rest.join('=').trim();
    if (key === 't' && /^\d{1,12}$/.test(value)) timestamp = Number(value);
    if (key === 'v1' && /^[0-9a-f]{64}$/i.test(value)) signatures.push(value.toLowerCase());
  }
  if (timestamp === null || signatures.length === 0) return null;
  return { timestamp, signatures };
}

export type VerifyResult = { ok: true } | { ok: false; reason: 'missing' | 'malformed' | 'expired' | 'mismatch' };

export interface VerifyInput {
  secret: string;
  header: string | undefined | null;
  method: string;
  pathWithQuery: string;
  body: string;
  now?: number;
  toleranceS?: number;
}

export function verifySignature(input: VerifyInput): VerifyResult {
  if (!input.header) return { ok: false, reason: 'missing' };
  const parsed = parseSignatureHeader(input.header);
  if (!parsed) return { ok: false, reason: 'malformed' };
  const now = input.now ?? Math.floor(Date.now() / 1000);
  if (Math.abs(now - parsed.timestamp) > (input.toleranceS ?? MAX_CLOCK_SKEW_S)) {
    return { ok: false, reason: 'expired' };
  }
  const expected = Buffer.from(
    computeSignature({
      secret: input.secret,
      method: input.method,
      pathWithQuery: input.pathWithQuery,
      body: input.body,
      timestamp: parsed.timestamp,
    }),
    'hex',
  );
  const matches = parsed.signatures.some((candidate) => {
    const given = Buffer.from(candidate, 'hex');
    return given.length === expected.length && timingSafeEqual(given, expected);
  });
  return matches ? { ok: true } : { ok: false, reason: 'mismatch' };
}

export function safeEqual(a: string, b: string): boolean {
  const left = createHmac('sha256', 'cmp').update(a).digest();
  const right = createHmac('sha256', 'cmp').update(b).digest();
  return timingSafeEqual(left, right) && a.length === b.length;
}
