import { describe, expect, it } from 'vitest';
import { computeSignature, parseSignatureHeader, safeEqual, signRequest, verifySignature } from '../src/clients/signing.js';

const VECTOR = {
  secret: 'test-secret',
  timestamp: 1700000000,
  method: 'POST',
  pathWithQuery: '/internal/v1/ping',
  body: '{"a":1}',
  hex: '723447fac31e5883463cdebeb32b961445f2ea9dd5877e8c2a5cf685f2f8abd7',
};

describe('internal request signing', () => {
  it('matches the shared test vector', () => {
    expect(computeSignature(VECTOR)).toBe(VECTOR.hex);
    expect(signRequest(VECTOR)).toBe(`t=${VECTOR.timestamp},v1=${VECTOR.hex}`);
  });

  it('upper-cases the method before signing', () => {
    expect(computeSignature({ ...VECTOR, method: 'post' })).toBe(VECTOR.hex);
  });

  it('signs an empty body for GET requests with a trailing dot', () => {
    const header = signRequest({ secret: 's', method: 'GET', pathWithQuery: '/internal/v1/x?a=1', body: '', timestamp: 10 });
    expect(verifySignature({ secret: 's', header, method: 'GET', pathWithQuery: '/internal/v1/x?a=1', body: '', now: 10 })).toEqual({ ok: true });
  });

  it('verifies a valid header within the window', () => {
    const header = `t=${VECTOR.timestamp},v1=${VECTOR.hex}`;
    expect(verifySignature({ ...VECTOR, header, now: VECTOR.timestamp + 299 })).toEqual({ ok: true });
  });

  it('rejects stale and future timestamps beyond 300s', () => {
    const header = `t=${VECTOR.timestamp},v1=${VECTOR.hex}`;
    expect(verifySignature({ ...VECTOR, header, now: VECTOR.timestamp + 301 })).toEqual({ ok: false, reason: 'expired' });
    expect(verifySignature({ ...VECTOR, header, now: VECTOR.timestamp - 301 })).toEqual({ ok: false, reason: 'expired' });
  });

  it('rejects tampered body, path, method and secret', () => {
    const header = `t=${VECTOR.timestamp},v1=${VECTOR.hex}`;
    const now = VECTOR.timestamp;
    expect(verifySignature({ ...VECTOR, header, now, body: '{"a":2}' }).ok).toBe(false);
    expect(verifySignature({ ...VECTOR, header, now, pathWithQuery: '/internal/v1/ping?x=1' }).ok).toBe(false);
    expect(verifySignature({ ...VECTOR, header, now, method: 'PUT' }).ok).toBe(false);
    expect(verifySignature({ ...VECTOR, header, now, secret: 'other' })).toEqual({ ok: false, reason: 'mismatch' });
  });

  it('reports missing and malformed headers', () => {
    expect(verifySignature({ ...VECTOR, header: undefined })).toEqual({ ok: false, reason: 'missing' });
    expect(verifySignature({ ...VECTOR, header: 'v1=abc' })).toEqual({ ok: false, reason: 'malformed' });
    expect(parseSignatureHeader('t=1,v1=zz')).toBeNull();
  });

  it('accepts any of several v1 signatures (secret rotation)', () => {
    const header = `t=${VECTOR.timestamp},v1=${'0'.repeat(64)},v1=${VECTOR.hex}`;
    expect(verifySignature({ ...VECTOR, header, now: VECTOR.timestamp })).toEqual({ ok: true });
  });

  it('compares secrets in constant time', () => {
    expect(safeEqual('abc', 'abc')).toBe(true);
    expect(safeEqual('abc', 'abd')).toBe(false);
    expect(safeEqual('abc', 'abcd')).toBe(false);
  });
});
