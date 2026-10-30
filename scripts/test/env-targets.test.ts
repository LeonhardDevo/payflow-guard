import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { join } from 'node:path';
import { parseEnvText } from '../lib/envfile.ts';
import { deriveProdEnv, envTarget, PROD_OVERRIDES } from '../lib/env-targets.ts';
import { parseProject } from '../lib/project.ts';

const project = parseProject(
  [
    'PRODUCT_NAME=Demo',
    'PRODUCT_SLUG=demo',
    'COMPOSE_PROJECT=demo',
    'DB_NAME=demo',
    'DB_USER=app',
    'DB_PASSWORD=app',
    'WEB_PORT=8100',
    'VITE_PORT=5173',
    'WORKER_PORT=8101',
    'PG_PORT=5441',
    'REDIS_PORT=6391',
    'MAIL_SMTP_PORT=1031',
    'MAIL_UI_PORT=8131',
  ].join('\n'),
  '/repo',
);

describe('envTarget', () => {
  it('dev reads apps/<app>/.env from .env.example unchanged', () => {
    const dev = envTarget(project, 'dev');
    assert.equal(dev.envPath('web'), join('/repo', 'apps', 'web', '.env'));
    assert.equal(dev.templatePath('worker'), join('/repo', 'apps', 'worker', '.env.example'));
    assert.equal(dev.derive('web', 'A=1\n'), 'A=1\n');
  });

  it('prod writes infra/env/.env.<app>', () => {
    const prod = envTarget(project, 'prod');
    assert.equal(prod.envPath('worker'), join('/repo', 'infra', 'env', '.env.worker'));
    assert.equal(prod.templatePath('worker'), join('/repo', 'apps', 'worker', '.env.example'));
  });
});

describe('deriveProdEnv', () => {
  it('applies production overrides and blanks every secret so it is generated fresh', () => {
    const template = 'NODE_ENV=development\nDEV_ROUTES=1\nINTERNAL_API_SECRET=dev-secret-0123456789abcdef\nSTRIPE_WEBHOOK_SECRET=whsec_test_local\nSMTP_PORT=1031\nBOT_RATE_LIMIT=20\n';
    const values = parseEnvText(deriveProdEnv('worker', template));
    assert.equal(values.NODE_ENV, 'production');
    assert.equal(values.DEV_ROUTES, '0');
    assert.equal(values.INTERNAL_API_SECRET, '');
    assert.equal(values.STRIPE_WEBHOOK_SECRET, '');
    assert.equal(values.SMTP_HOST, 'mailpit');
    assert.equal(values.SMTP_PORT, '1025');
    assert.equal(values.BOT_RATE_LIMIT, '20');
  });

  it('starts with a header explaining that compose injects the topology', () => {
    assert.match(deriveProdEnv('web', 'APP_ENV=local\n'), /^# Production environment of the web container/);
  });

  it('turns debug off for web and production mode for every app', () => {
    assert.equal(PROD_OVERRIDES.web.APP_DEBUG, 'false');
    assert.equal(PROD_OVERRIDES.web.APP_ENV, 'production');
    assert.equal(PROD_OVERRIDES.worker.NODE_ENV, 'production');
  });
});
