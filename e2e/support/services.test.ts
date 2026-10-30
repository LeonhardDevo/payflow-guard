import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { AI_OFFLINE_ENV, e2eOverrides, rewriteDatabaseUrl, type E2EContext } from './services.ts';

const ctx: E2EContext = {
  devDb: 'payflow',
  e2eDb: 'payflow_e2e',
  baseURL: 'http://127.0.0.1:8100',
  workerURL: 'http://127.0.0.1:8101',
  aiURL: 'http://127.0.0.1:8101',
  slug: 'payflow',
};

describe('rewriteDatabaseUrl', () => {
  it('rewrites every postgres driver prefix and keeps query strings', () => {
    assert.equal(rewriteDatabaseUrl('postgres://app:app@127.0.0.1:5441/payflow', 'payflow', 'payflow_e2e'), 'postgres://app:app@127.0.0.1:5441/payflow_e2e');
    assert.equal(
      rewriteDatabaseUrl('postgresql+psycopg://app:app@127.0.0.1:5441/payflow?sslmode=disable', 'payflow', 'payflow_e2e'),
      'postgresql+psycopg://app:app@127.0.0.1:5441/payflow_e2e?sslmode=disable',
    );
  });

  it('leaves other databases and non-postgres URLs alone', () => {
    assert.equal(rewriteDatabaseUrl('postgres://app:app@127.0.0.1:5441/payflow_test', 'payflow', 'payflow_e2e'), null);
    assert.equal(rewriteDatabaseUrl('redis://127.0.0.1:6391/0', 'payflow', 'payflow_e2e'), null);
    assert.equal(rewriteDatabaseUrl('http://127.0.0.1:8100/payflow', 'payflow', 'payflow_e2e'), null);
  });
});

describe('e2eOverrides', () => {
  it('web: isolated database, cache prefixes, fake billing and the e2e origin', () => {
    const overrides = e2eOverrides('web', { DB_DATABASE: 'payflow', DB_URL: '' }, ctx);
    assert.equal(overrides.APP_ENV, 'e2e');
    assert.equal(overrides.DB_DATABASE, 'payflow_e2e');
    assert.equal(overrides.APP_URL, ctx.baseURL);
    assert.equal(overrides.BILLING_DRIVER, 'fake');
    assert.equal(overrides.DEV_ROUTES, '1');
    assert.equal(overrides.TELEGRAM_MODE, 'disabled');
    assert.match(overrides.REDIS_PREFIX!, /e2e/);
    assert.equal(overrides.DB_URL, undefined);
  });

  it('worker: rewrites DATABASE_URL, enables dev routes and keeps Telegram offline', () => {
    const overrides = e2eOverrides('worker', { DATABASE_URL: 'postgres://app:app@127.0.0.1:5441/payflow', TELEGRAM_BOT_TOKEN: 'real' }, ctx);
    assert.equal(overrides.DATABASE_URL, 'postgres://app:app@127.0.0.1:5441/payflow_e2e');
    assert.equal(overrides.DEV_ROUTES, '1');
    assert.equal(overrides.TELEGRAM_BOT_TOKEN, '');
    assert.equal(overrides.TELEGRAM_MODE, 'disabled');
    assert.equal(overrides.WEB_URL, ctx.baseURL);
    assert.notEqual(overrides.QUEUE_PREFIX, 'bull');
  });

  it('worker: calls web through the internal server while links keep the public origin', () => {
    const overrides = e2eOverrides('worker', {}, { ...ctx, webInternalURL: 'http://127.0.0.1:8102' });
    assert.equal(overrides.WEB_URL, 'http://127.0.0.1:8102');
    assert.equal(overrides.WEB_PUBLIC_URL, ctx.baseURL);
  });

  it('worker: serves the RAG API in-process with offline providers', () => {
    const overrides = e2eOverrides('worker', { DATABASE_URL: 'postgresql://app:app@127.0.0.1:5441/payflow?sslmode=disable' }, ctx);
    assert.equal(overrides.DATABASE_URL, 'postgresql://app:app@127.0.0.1:5441/payflow_e2e?sslmode=disable');
    assert.equal(overrides.WORKER_ROLES, 'all');
    assert.equal(overrides.AI_URL, ctx.aiURL);
    for (const [key, value] of Object.entries(AI_OFFLINE_ENV)) assert.equal(overrides[key], value);
  });
});
