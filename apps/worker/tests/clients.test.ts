import { createServer, type IncomingMessage, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { AiClient } from '../src/clients/ai.js';
import { UpstreamHttpError, UpstreamNetworkError, isRetryableError } from '../src/clients/errors.js';
import { buildPathWithQuery, internalFetch } from '../src/clients/internalFetch.js';
import { verifySignature } from '../src/clients/signing.js';
import { WebClient } from '../src/clients/web.js';

const SECRET = 'shared-test-secret-0123456789';

interface Captured {
  method: string;
  url: string;
  headers: IncomingMessage['headers'];
  body: string;
  signatureOk: boolean;
}

type Responder = (request: Captured) => { status: number; body?: unknown };

let server: Server;
let baseUrl: string;
let captured: Captured[] = [];
let responder: Responder = () => ({ status: 200, body: { ok: true } });

beforeAll(async () => {
  server = createServer((req, res) => {
    const chunks: Buffer[] = [];
    req.on('data', (chunk: Buffer) => chunks.push(chunk));
    req.on('end', () => {
      const body = Buffer.concat(chunks).toString('utf8');
      const request: Captured = {
        method: req.method ?? '',
        url: req.url ?? '',
        headers: req.headers,
        body,
        signatureOk: verifySignature({
          secret: SECRET,
          header: req.headers['x-signature'] as string | undefined,
          method: req.method ?? '',
          pathWithQuery: req.url ?? '',
          body,
        }).ok,
      };
      captured.push(request);
      const reply = responder(request);
      res.writeHead(reply.status, { 'content-type': 'application/json' });
      res.end(reply.body === undefined ? '' : JSON.stringify(reply.body));
    });
  });
  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
  baseUrl = `http://127.0.0.1:${(server.address() as AddressInfo).port}`;
});

afterAll(async () => {
  await new Promise<void>((resolve) => server.close(() => resolve()));
});

beforeEach(() => {
  captured = [];
  responder = () => ({ status: 200, body: { ok: true } });
});

const target = () => ({ service: 'web' as const, baseUrl, secret: SECRET, timeoutMs: 2000 });

describe('internalFetch', () => {
  it('sends signed requests that the receiver can verify', async () => {
    const response = await internalFetch(target(), {
      method: 'POST',
      path: '/internal/v1/deliveries',
      body: { reference: { type: 'invoice', id: 5 }, status: 'sent', text: 'Привет' },
      requestId: '11111111-1111-4111-8111-111111111111',
    });
    expect(response.status).toBe(200);
    const request = captured[0]!;
    expect(request.signatureOk).toBe(true);
    expect(request.headers['x-service']).toBe('worker');
    expect(request.headers['x-request-id']).toBe('11111111-1111-4111-8111-111111111111');
    expect(request.headers['content-type']).toBe('application/json');
    expect(JSON.parse(request.body)).toMatchObject({ text: 'Привет' });
  });

  it('signs GET requests including the query string', async () => {
    await internalFetch(target(), { method: 'GET', path: '/internal/v1/telegram/context', query: { telegram_user_id: 42, skip: undefined } });
    expect(captured[0]!.url).toBe('/internal/v1/telegram/context?telegram_user_id=42');
    expect(captured[0]!.signatureOk).toBe(true);
    expect(captured[0]!.body).toBe('');
  });

  it('classifies HTTP and network failures', async () => {
    responder = () => ({ status: 503, body: { message: 'maintenance' } });
    const serverError = await internalFetch(target(), { method: 'GET', path: '/x' }).catch((error: unknown) => error);
    expect(serverError).toBeInstanceOf(UpstreamHttpError);
    expect((serverError as UpstreamHttpError).message).toContain('maintenance');
    expect(isRetryableError(serverError)).toBe(true);

    responder = () => ({ status: 422, body: { message: 'invalid' } });
    const clientError = await internalFetch(target(), { method: 'GET', path: '/x' }).catch((error: unknown) => error);
    expect(isRetryableError(clientError)).toBe(false);

    const network = await internalFetch({ ...target(), baseUrl: 'http://127.0.0.1:1' }, { method: 'GET', path: '/x' }).catch(
      (error: unknown) => error,
    );
    expect(network).toBeInstanceOf(UpstreamNetworkError);
    expect(isRetryableError(network)).toBe(true);
  });

  it('builds paths safely', () => {
    expect(buildPathWithQuery('/a', { q: 'a b', n: 1, t: true })).toBe('/a?q=a+b&n=1&t=true');
    expect(() => buildPathWithQuery('a')).toThrow();
  });
});

describe('WebClient', () => {
  it('returns null context for unlinked users and parses linked context', async () => {
    const web = new WebClient(target());
    responder = () => ({ status: 404, body: { message: 'not linked' } });
    expect(await web.telegramContext({ telegramUserId: 1 })).toBeNull();

    responder = () => ({
      status: 200,
      body: { user: { id: 1, name: 'Ann', locale: 'ru' }, workspace: { id: 2, name: 'W', plan: 'pro' }, role: 'owner', permissions: ['a'] },
    });
    const context = await web.telegramContext({ userId: 1 });
    expect(context).toMatchObject({ user: { id: 1, locale: 'ru' }, role: 'owner', permissions: ['a'] });
    expect(captured.at(-1)!.url).toBe('/internal/v1/telegram/context?user_id=1');
  });

  it('maps link responses', async () => {
    const web = new WebClient(target());
    const input = { token: 'tok_12345678', telegram_user_id: 5, chat_id: 5, username: null, language_code: 'ru' };
    responder = () => ({ status: 200, body: { user: { id: 1, name: 'Ann', locale: 'en' }, workspace: { id: 2, name: 'W' } } });
    expect(await web.linkTelegram(input)).toEqual({ ok: true, userName: 'Ann', workspaceName: 'W', locale: 'en' });
    responder = () => ({ status: 410 });
    expect(await web.linkTelegram(input)).toEqual({ ok: false, reason: 'invalid_token' });
    responder = () => ({ status: 409 });
    expect(await web.linkTelegram(input)).toEqual({ ok: false, reason: 'conflict' });
    responder = () => ({ status: 500 });
    await expect(web.linkTelegram(input)).rejects.toBeInstanceOf(UpstreamHttpError);
  });

  it('posts deliveries, stripe events, document status and query logs to the contract paths', async () => {
    const web = new WebClient(target());
    expect(await web.ping()).toBe(true);
    await web.reportDelivery({ reference: { type: 'x', id: 1 }, channel: 'email', status: 'sent' });
    await web.forwardStripeEvent({ id: 'evt_1', type: 'checkout.session.completed' });
    await web.reportDocumentStatus(9, { status: 'failed', error: 'boom' });
    await web.logAiQuery({
      workspace_id: 1,
      user_id: 2,
      channel: 'telegram',
      question: 'q',
      answer: 'a',
      refused: false,
      confidence: 0.5,
      citations: [],
      tools_used: [],
      latency_ms: 10,
      provider: 'mock',
      model: 'm',
    });
    expect(captured.map((request) => `${request.method} ${request.url}`)).toEqual([
      'GET /internal/v1/ping',
      'POST /internal/v1/deliveries',
      'POST /internal/v1/stripe/events',
      'POST /internal/v1/documents/9/status',
      'POST /internal/v1/ai-queries',
    ]);
    expect(captured.every((request) => request.signatureOk)).toBe(true);
  });
});

describe('WebClient payment endpoints', () => {
  it('resolves endpoint keys, maps unknown keys to null and forwards events with the account', async () => {
    const web = new WebClient(target());
    responder = () => ({ status: 200, body: { payment_account_id: 12, workspace_id: 4, webhook_secret: 'whsec_fake_x', driver: 'fake' } });
    expect(await web.paymentEndpoint('Lab0000000000000000000000000000a')).toMatchObject({ payment_account_id: 12, workspace_id: 4, driver: 'fake' });
    responder = () => ({ status: 404, body: { error: 'not_found' } });
    expect(await web.paymentEndpoint('Missing0000000000000000000000000')).toBeNull();
    responder = () => ({ status: 500, body: {} });
    await expect(web.paymentEndpoint('Broken00000000000000000000000000')).rejects.toBeInstanceOf(UpstreamHttpError);
    responder = () => ({ status: 200, body: { result: 'processed' } });
    const event = { id: 'evt_1', object: 'event' as const, type: 'charge.refunded' };
    expect(await web.forwardPaymentEvent({ driver: 'stripe', payment_account_id: 3, workspace_id: 1, event })).toEqual({ result: 'processed' });
    expect(captured.map((request) => `${request.method} ${request.url}`)).toEqual([
      'GET /internal/v1/payments/endpoints/Lab0000000000000000000000000000a',
      'GET /internal/v1/payments/endpoints/Missing0000000000000000000000000',
      'GET /internal/v1/payments/endpoints/Broken00000000000000000000000000',
      'POST /internal/v1/payments/events',
    ]);
    expect(JSON.parse(captured.at(-1)!.body)).toEqual({ driver: 'stripe', payment_account_id: 3, workspace_id: 1, event });
    expect(captured.every((request) => request.signatureOk)).toBe(true);
  });
});

describe('AiClient', () => {
  const ai = () => new AiClient({ ...target(), service: 'ai' }, { chatMs: 2000, ingestMs: 2000 });

  it('calls /v1/chat and fills response defaults', async () => {
    responder = () => ({ status: 200, body: { answer_markdown: 'Hi', citations: [{ document_id: 1, title: 'Doc' }] } });
    const response = await ai().chat({
      workspace_id: 1,
      user: { id: 1, role: 'member', locale: 'en', permissions: [] },
      channel: 'telegram',
      message: 'hello',
    });
    expect(response).toMatchObject({ answer_markdown: 'Hi', answer_html: '', refused: false, actions: [], confidence: 0 });
    expect(captured[0]!.url).toBe('/v1/chat');
  });

  it('treats a missing document as already deleted', async () => {
    responder = () => ({ status: 404 });
    await expect(ai().deleteDocument(5, 1)).resolves.toBeUndefined();
    expect(captured[0]!.url).toBe('/v1/documents/5?workspace_id=1');
    expect(captured[0]!.method).toBe('DELETE');
  });

  it('ingests and re-embeds via the contract paths', async () => {
    responder = () => ({ status: 200, body: { pages: 2, chunks: 5, ocr_used: true } });
    expect(await ai().ingestDocument(7, { workspace_id: 1, ai_external_allowed: false, metadata: {} })).toMatchObject({ pages: 2, chunks: 5, ocr_used: true });
    await ai().reembedWorkspace(1, false);
    expect(captured.map((request) => request.url)).toEqual(['/v1/documents/7/ingest', '/v1/workspaces/1/reembed']);
  });

  it('purges a user and prunes memory via the contract paths', async () => {
    responder = () => ({ status: 404 });
    await expect(ai().purgeUser(9)).resolves.toBeUndefined();
    responder = () => ({ status: 200, body: { pruned: 3 } });
    await ai().pruneMemory({ default_days: 180, workspaces: [{ workspace_id: 2, days: 7 }] });
    expect(captured.map((request) => `${request.method} ${request.url}`)).toEqual(['DELETE /v1/users/9', 'POST /v1/privacy/prune-memory']);
  });
});
