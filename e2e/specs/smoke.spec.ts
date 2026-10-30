import { expect, test } from '../fixtures.ts';

test.describe('smoke', () => {
  test('web, worker and the RAG API report healthy', { tag: '@flow' }, async ({ request, app }) => {
    const web = await request.get('/up');
    expect(web.status()).toBe(200);
    const worker = await request.get(`${app.config.workerURL}/healthz`);
    expect(worker.status()).toBe(200);
    const rag = await request.get(`${app.config.aiURL}/healthz`);
    expect(rag.status()).toBe(200);
  });

  test('internal APIs reject unsigned requests', { tag: '@flow' }, async ({ request, app }) => {
    expect((await request.get('/internal/v1/ping')).status()).toBe(401);
    expect((await request.post(`${app.config.aiURL}/v1/chat`, { data: {} })).status()).toBe(401);
  });

  test('responses carry the security headers (CSP default-src self)', { tag: '@flow' }, async ({ request }) => {
    const response = await request.get('/');
    const headers = response.headers();
    expect(headers['x-content-type-options']).toBe('nosniff');
    expect(headers['content-security-policy'] ?? '', 'CSP must not allow third-party origins').toMatch(/default-src 'self'/);
  });
});
