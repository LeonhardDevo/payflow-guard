import { describe, expect, it } from 'vitest';
import { ConfigError, DEV_FALLBACK_SECRET, DEV_STRIPE_WEBHOOK_SECRET, loadConfig } from '../src/config.js';

const base = {
  PRODUCT_NAME: 'Acme Desk',
  PRODUCT_SLUG: 'acme',
  DATABASE_URL: 'postgres://app:app@127.0.0.1:5441/acme',
  REDIS_URL: 'redis://127.0.0.1:6391',
  INTERNAL_API_SECRET: 'dev-internal-secret-0123456789',
  WEB_URL: 'http://127.0.0.1:8100/',
  AI_URL: 'http://127.0.0.1:8101',
};

describe('config', () => {
  it('applies documented defaults', () => {
    const config = loadConfig(base);
    expect(config.port).toBe(8101);
    expect(config.internal.webUrl).toBe('http://127.0.0.1:8100');
    expect(config.internal.webPublicUrl).toBe('http://127.0.0.1:8100');
    expect(config.stripe.webhookSecret).toBe('whsec_test_local');
    expect(config.telegram.mode).toBe('polling');
    expect(config.telegram.botUsername).toBe('acme_bot');
    expect(config.outbox).toMatchObject({ table: 'public.outbox_messages', channel: 'outbox', pollMs: 2000, batchSize: 100 });
    expect(config.smtp.from).toBe('"Acme Desk" <no-reply@acme.test>');
    expect(config.redis.keyPrefix).toBe('acme:worker:');
    expect(config.dashboard).toBeNull();
  });

  it('parses booleans and enables the dashboard only with both credentials', () => {
    const config = loadConfig({ ...base, DEV_ROUTES: '1', SMTP_SECURE: 'true', QUEUE_DASHBOARD_USER: 'u', QUEUE_DASHBOARD_PASSWORD: 'p' });
    expect(config.devRoutes).toBe(true);
    expect(config.smtp.secure).toBe(true);
    expect(config.dashboard).toEqual({ user: 'u', password: 'p' });
  });

  const production = {
    ...base,
    NODE_ENV: 'production',
    INTERNAL_KEY_WEB_WORKER: 'w'.repeat(40),
    INTERNAL_KEY_WORKER_AI: 'a'.repeat(40),
    STRIPE_WEBHOOK_SECRET: 'whsec_' + 'r'.repeat(32),
  };

  it('never enables dev routes in production and requires strong pair keys', () => {
    expect(() => loadConfig({ ...base, NODE_ENV: 'production', DEV_ROUTES: '1' })).toThrow(ConfigError);
    const config = loadConfig({ ...production, DEV_ROUTES: '1' });
    expect(config.devRoutes).toBe(false);
    expect(config.internal.keys).toMatchObject({ web: 'w'.repeat(40), ai: 'a'.repeat(40) });
    expect([...config.roles]).toEqual(['jobs', 'bot', 'ingress']);
  });

  it('signs with per-pair keys and falls back to the shared secret outside production (ADR-0010)', () => {
    expect(loadConfig(base).internal.keys).toEqual({ web: base.INTERNAL_API_SECRET, ai: base.INTERNAL_API_SECRET, webAi: base.INTERNAL_API_SECRET });
    const config = loadConfig({ ...base, INTERNAL_KEY_WEB_WORKER: 'w'.repeat(32), INTERNAL_KEY_WORKER_AI: 'a'.repeat(32) });
    expect(config.internal.keys).toEqual({ web: 'w'.repeat(32), ai: 'a'.repeat(32), webAi: base.INTERNAL_API_SECRET });
    expect(() => loadConfig({ ...production, INTERNAL_KEY_WORKER_AI: '' })).toThrow(/INTERNAL_KEY_WORKER_AI/);
    expect(() => loadConfig({ ...production, INTERNAL_KEY_WEB_WORKER: 'short' })).toThrow(/INTERNAL_KEY_WEB_WORKER/);
    expect(() => loadConfig({ ...production, INTERNAL_KEY_WORKER_AI: 'w'.repeat(40) })).toThrow(/must differ/);
  });

  it('runs every role in development and separate roles in production (ADR-0014)', () => {
    expect([...loadConfig(base).roles]).toEqual(['jobs', 'bot', 'ingress', 'rag']);
    expect([...loadConfig({ ...base, WORKER_ROLES: 'rag' }).roles]).toEqual(['rag']);
    expect(() => loadConfig({ ...base, WORKER_ROLES: 'jobs,nope' })).toThrow(/WORKER_ROLES/);
    expect(() => loadConfig({ ...production, WORKER_ROLES: 'all' })).toThrow(/WORKER_ROLES/);
    const ragOnly = { ...production, WORKER_ROLES: 'rag', INTERNAL_KEY_WEB_WORKER: '', STRIPE_WEBHOOK_SECRET: '', AI_SETTINGS_KEY: 's'.repeat(40) };
    expect(() => loadConfig(ragOnly)).toThrow(/INTERNAL_KEY_WEB_AI/);
    const rag = loadConfig({ ...ragOnly, INTERNAL_KEY_WEB_AI: 'r'.repeat(40) });
    expect([...rag.roles]).toEqual(['rag']);
    expect(rag.internal.keys).toMatchObject({ ai: 'a'.repeat(40), webAi: 'r'.repeat(40) });
    expect(() => loadConfig({ ...ragOnly, INTERNAL_KEY_WEB_AI: 'a'.repeat(40) })).toThrow(/must differ/);
    expect(() => loadConfig({ ...ragOnly, INTERNAL_KEY_WEB_AI: 'r'.repeat(40), AI_SETTINGS_KEY: '' })).toThrow(/AI_SETTINGS_KEY/);
    expect(loadConfig(base).rag.rag.externalDefault).toBe(false);
  });

  it('refuses the public development Stripe secret in production', () => {
    expect(() => loadConfig({ ...production, STRIPE_WEBHOOK_SECRET: '' })).toThrow(/STRIPE_WEBHOOK_SECRET/);
    expect(() => loadConfig({ ...production, STRIPE_WEBHOOK_SECRET: DEV_STRIPE_WEBHOOK_SECRET })).toThrow(/STRIPE_WEBHOOK_SECRET/);
    expect(() => loadConfig({ ...production, STRIPE_WEBHOOK_SECRET: 'not-a-stripe-secret-0123456789' })).toThrow(/STRIPE_WEBHOOK_SECRET/);
    expect(loadConfig(production).stripe.webhookSecret).toBe(production.STRIPE_WEBHOOK_SECRET);
  });

  it('treats empty values as unset and falls back to the dev secret outside production', () => {
    const config = loadConfig({ ...base, INTERNAL_API_SECRET: '', STRIPE_WEBHOOK_SECRET: '', TELEGRAM_API_ROOT: ' ', QUEUE_DASHBOARD_PASSWORD: '' });
    expect(config.internal.secret).toBe(DEV_FALLBACK_SECRET);
    expect(config.internal.usingDevSecret).toBe(true);
    expect(config.stripe.webhookSecret).toBe('whsec_test_local');
    expect(config.telegram.apiRoot).toBe('https://api.telegram.org');
    expect(config.dashboard).toBeNull();
    expect(loadConfig(base).internal.usingDevSecret).toBe(false);
    expect(() => loadConfig({ ...base, NODE_ENV: 'production', INTERNAL_API_SECRET: '' })).toThrow(/INTERNAL_KEY_WEB_WORKER/);
  });

  it('requires a webhook secret in webhook mode with a token', () => {
    expect(() => loadConfig({ ...base, TELEGRAM_MODE: 'webhook', TELEGRAM_BOT_TOKEN: '1:abc' })).toThrow(/TELEGRAM_WEBHOOK_SECRET/);
  });

  it('rejects unsafe identifiers and missing product identity', () => {
    expect(() => loadConfig({ ...base, OUTBOX_TABLE: 'public.outbox; drop table x' })).toThrow(ConfigError);
    expect(() => loadConfig({ ...base, PRODUCT_SLUG: 'Bad Slug' })).toThrow(ConfigError);
    const { PRODUCT_NAME: _omit, ...withoutName } = base;
    expect(() => loadConfig(withoutName)).toThrow(/PRODUCT_NAME/);
  });
});
