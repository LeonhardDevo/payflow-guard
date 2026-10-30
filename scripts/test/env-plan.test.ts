import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { checkInfraTargets, planEnvSync, SECRET_RULES, type AppEnvState } from '../lib/env-plan.ts';
import { isLaravelAppKey } from '../lib/secrets.ts';
import { parseProject, type AppName } from '../lib/project.ts';
import type { EnvValues } from '../lib/envfile.ts';

const project = parseProject(
  [
    'PRODUCT_NAME="Demo Product"',
    'PRODUCT_SLUG=demo-product',
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

const WEB_EXAMPLE: EnvValues = {
  APP_NAME: 'Laravel',
  APP_KEY: '',
  DB_CONNECTION: 'pgsql',
  DB_PORT: '5441',
  REDIS_PORT: '6391',
  INTERNAL_API_SECRET: '',
  STRIPE_WEBHOOK_SECRET: '',
};
const WORKER_EXAMPLE: EnvValues = {
  PRODUCT_NAME: '',
  PRODUCT_SLUG: '',
  DATABASE_URL: 'postgres://app:app@127.0.0.1:5441/demo',
  INTERNAL_API_SECRET: 'change-me',
  STRIPE_WEBHOOK_SECRET: '',
  QUEUE_DASHBOARD_PASSWORD: '',
};

function fresh(): AppEnvState[] {
  return [
    { app: 'web', example: WEB_EXAMPLE, current: null },
    { app: 'worker', example: WORKER_EXAMPLE, current: null },
  ];
}

function apply(states: AppEnvState[], plan: ReturnType<typeof planEnvSync>): AppEnvState[] {
  return states.map((state) => {
    const base = state.current ?? (plan.create.includes(state.app) ? { ...state.example! } : null);
    if (!base) return state;
    return { ...state, current: { ...base, ...(plan.updates[state.app] ?? {}) } };
  });
}

const valueIn = (states: AppEnvState[], app: AppName, key: string) => states.find((s) => s.app === app)!.current![key];

describe('planEnvSync', () => {
  it('creates missing .env files and generates every secret once', () => {
    const plan = planEnvSync(project, fresh());
    assert.deepEqual(plan.create, ['web', 'worker']);
    const after = apply(fresh(), plan);

    const internal = valueIn(after, 'web', 'INTERNAL_API_SECRET');
    assert.match(internal!, /^[0-9a-f]{64}$/);
    assert.equal(valueIn(after, 'worker', 'INTERNAL_API_SECRET'), internal);

    const pairs = { INTERNAL_KEY_WEB_WORKER: ['web', 'worker'], INTERNAL_KEY_WEB_AI: ['web', 'worker'], INTERNAL_KEY_WORKER_AI: ['worker'] } as const;
    const pairValues = new Set<string>();
    for (const [key, apps] of Object.entries(pairs)) {
      const value = valueIn(after, apps[0], key);
      assert.match(value!, /^[0-9a-f]{64}$/, key);
      for (const app of ['web', 'worker'] as const) {
        assert.equal(valueIn(after, app, key), (apps as readonly string[]).includes(app) ? value : undefined, key + ' in ' + app);
      }
      pairValues.add(value!);
    }
    assert.equal(pairValues.size, 3);
    assert.match(valueIn(after, 'worker', 'AI_SETTINGS_KEY')!, /^[0-9a-f]{64}$/);
    assert.equal(valueIn(after, 'web', 'AI_SETTINGS_KEY'), undefined);

    const stripe = valueIn(after, 'web', 'STRIPE_WEBHOOK_SECRET');
    assert.match(stripe!, /^whsec_/);
    assert.equal(valueIn(after, 'worker', 'STRIPE_WEBHOOK_SECRET'), stripe);

    assert.equal(isLaravelAppKey(valueIn(after, 'web', 'APP_KEY')!), true);
    assert.ok(valueIn(after, 'worker', 'QUEUE_DASHBOARD_PASSWORD'));
  });

  it('is idempotent: a second run plans nothing and never rotates secrets', () => {
    const after = apply(fresh(), planEnvSync(project, fresh()));
    const again = planEnvSync(project, after);
    assert.deepEqual(again.create, []);
    assert.deepEqual(again.updates, {});
  });

  it('propagates an existing shared secret instead of generating a new one', () => {
    const states = fresh();
    states[1] = { app: 'worker', example: WORKER_EXAMPLE, current: { ...WORKER_EXAMPLE, INTERNAL_API_SECRET: 'w'.repeat(40) } };
    const after = apply(states, planEnvSync(project, states));
    assert.equal(valueIn(after, 'web', 'INTERNAL_API_SECRET'), 'w'.repeat(40));
  });

  it('aligns diverging shared secrets to the web value and warns', () => {
    const states: AppEnvState[] = [
      { app: 'web', example: WEB_EXAMPLE, current: { ...WEB_EXAMPLE, INTERNAL_API_SECRET: 'a'.repeat(32) } },
      { app: 'worker', example: WORKER_EXAMPLE, current: { ...WORKER_EXAMPLE, INTERNAL_API_SECRET: 'b'.repeat(32) } },
    ];
    const plan = planEnvSync(project, states);
    assert.equal(plan.updates.worker?.INTERNAL_API_SECRET, 'a'.repeat(32));
    assert.equal(plan.updates.web?.INTERNAL_API_SECRET, undefined);
    assert.ok(plan.warnings.some((w) => w.includes('INTERNAL_API_SECRET in apps/worker/.env differed')));
  });

  it('regenerates invalid keys but keeps valid ones', () => {
    const valid = { ...WEB_EXAMPLE, APP_KEY: 'base64:not-a-key', INTERNAL_API_SECRET: 'k'.repeat(32), STRIPE_WEBHOOK_SECRET: 'whsec_kept' };
    const plan = planEnvSync(project, [{ app: 'web', example: WEB_EXAMPLE, current: valid }]);
    assert.equal(isLaravelAppKey(plan.updates.web!.APP_KEY!), true);
    assert.equal(plan.updates.web?.INTERNAL_API_SECRET, undefined);
    assert.equal(plan.updates.web?.STRIPE_WEBHOOK_SECRET, undefined);
  });

  it('only writes optional secrets into apps that declare them', () => {
    const plan = planEnvSync(project, fresh());
    assert.equal(plan.updates.worker?.TELEGRAM_WEBHOOK_SECRET, undefined);
    assert.ok(plan.updates.worker?.QUEUE_DASHBOARD_PASSWORD);
  });

  it('fills product identity from scripts/project.env', () => {
    const plan = planEnvSync(project, fresh());
    assert.equal(plan.updates.worker?.PRODUCT_NAME, 'Demo Product');
    assert.equal(plan.updates.worker?.PRODUCT_SLUG, 'demo-product');
    assert.equal(plan.updates.web?.APP_NAME, 'Demo Product');
    assert.equal(plan.updates.web?.PRODUCT_NAME, undefined);
  });

  it('still creates .env with the shared values for apps without a template', () => {
    const plan = planEnvSync(project, [
      { app: 'web', example: WEB_EXAMPLE, current: null },
      { app: 'worker', example: null, current: null },
    ]);
    assert.ok(plan.create.includes('worker'));
    assert.ok(plan.warnings.some((w) => w.includes('apps/worker has no .env.example')));
    assert.equal(plan.updates.worker?.INTERNAL_API_SECRET, plan.updates.web?.INTERNAL_API_SECRET);
    assert.equal(plan.updates.worker?.STRIPE_WEBHOOK_SECRET, plan.updates.web?.STRIPE_WEBHOOK_SECRET);
  });

  it('rule table covers every cross-service secret of PLATFORM.md §4', () => {
    const keys = SECRET_RULES.map((rule) => rule.key);
    for (const key of ['INTERNAL_API_SECRET', 'STRIPE_WEBHOOK_SECRET', 'AI_SETTINGS_KEY', 'APP_KEY', 'INTERNAL_KEY_WEB_WORKER', 'INTERNAL_KEY_WEB_AI', 'INTERNAL_KEY_WORKER_AI']) assert.ok(keys.includes(key), key);
  });
});

describe('checkInfraTargets', () => {
  it('flags the native Postgres/Redis ports and SQLite', () => {
    const warnings = checkInfraTargets(
      project,
      new Map<AppName, EnvValues>([
        ['web', { DB_CONNECTION: 'sqlite', REDIS_PORT: '6379' }],
        ['worker', { DATABASE_URL: 'postgres://app:app@localhost:5432/demo', REDIS_URL: 'redis://127.0.0.1:6379' }],
      ]),
    );
    assert.ok(warnings.some((w) => w.includes('DB_CONNECTION=sqlite')));
    assert.ok(warnings.some((w) => w.includes('REDIS_PORT=6379')));
    assert.ok(warnings.some((w) => w.includes('DATABASE_URL') && w.includes('native Postgres')));
    assert.ok(warnings.some((w) => w.includes('REDIS_URL') && w.includes('native Redis')));
  });

  it('accepts the docker ports', () => {
    const warnings = checkInfraTargets(
      project,
      new Map<AppName, EnvValues>([
        ['web', { DB_CONNECTION: 'pgsql', DB_PORT: '5441', REDIS_PORT: '6391' }],
        ['worker', { DATABASE_URL: 'postgres://app:app@127.0.0.1:5441/demo', REDIS_URL: 'redis://127.0.0.1:6391' }],
      ]),
    );
    assert.deepEqual(warnings, []);
  });
});
