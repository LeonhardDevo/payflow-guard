import type { AppName, Project } from './project.ts';
import { isPlaceholder, type EnvValues } from './envfile.ts';
import { isLaravelAppKey, laravelAppKey, randomHex, stripeWebhookSecret } from './secrets.ts';

export interface AppEnvState {
  app: AppName;
  example: EnvValues | null;
  current: EnvValues | null;
}

export interface SecretRule {
  key: string;
  apps: readonly AppName[];
  shared: boolean;
  onlyIfDeclared: boolean;
  generate: () => string;
  isValid?: (value: string) => boolean;
}

const PRIORITY: readonly AppName[] = ['web', 'worker'];

export const SECRET_RULES: readonly SecretRule[] = [
  { key: 'APP_KEY', apps: ['web'], shared: false, onlyIfDeclared: false, generate: laravelAppKey, isValid: isLaravelAppKey },
  {
    key: 'INTERNAL_API_SECRET',
    apps: ['web', 'worker'],
    shared: true,
    onlyIfDeclared: false,
    generate: () => randomHex(32),
    isValid: (value) => value.length >= 16,
  },
  ...(
    [
      ['INTERNAL_KEY_WEB_WORKER', ['web', 'worker']],
      ['INTERNAL_KEY_WEB_AI', ['web', 'worker']],
      ['INTERNAL_KEY_WORKER_AI', ['worker']],
    ] as const
  ).map(
    ([key, apps]): SecretRule => ({
      key,
      apps,
      shared: true,
      onlyIfDeclared: false,
      generate: () => randomHex(32),
      isValid: (value) => value.length >= 32,
    }),
  ),
  { key: 'STRIPE_WEBHOOK_SECRET', apps: ['web', 'worker'], shared: true, onlyIfDeclared: false, generate: stripeWebhookSecret },
  { key: 'AI_SETTINGS_KEY', apps: ['worker'], shared: false, onlyIfDeclared: false, generate: () => randomHex(32), isValid: (value) => value.length >= 32 },
  { key: 'QUEUE_DASHBOARD_PASSWORD', apps: ['worker'], shared: false, onlyIfDeclared: true, generate: () => randomHex(12) },
  { key: 'TELEGRAM_WEBHOOK_SECRET', apps: ['worker'], shared: false, onlyIfDeclared: true, generate: () => randomHex(24) },
];

export interface EnvPlan {
  create: AppName[];
  updates: Partial<Record<AppName, Record<string, string>>>;
  notes: string[];
  warnings: string[];
}

function usable(rule: SecretRule, value: string | undefined): value is string {
  if (value === undefined || isPlaceholder(value)) return false;
  return rule.isValid ? rule.isValid(value.trim()) : true;
}

function declares(state: AppEnvState, key: string): boolean {
  return (state.example !== null && key in state.example) || (state.current !== null && key in state.current);
}

export function planEnvSync(
  project: Project,
  states: readonly AppEnvState[],
  rules: readonly SecretRule[] = SECRET_RULES,
  where: (app: AppName) => string = (app) => `apps/${app}/.env`,
): EnvPlan {
  const plan: EnvPlan = { create: [], updates: {}, notes: [], warnings: [] };
  const effective = new Map<AppName, EnvValues>();

  for (const state of states) {
    if (state.current) {
      effective.set(state.app, { ...state.current });
    } else if (state.example) {
      plan.create.push(state.app);
      plan.notes.push(`${where(state.app)} created from apps/${state.app}/.env.example`);
      effective.set(state.app, { ...state.example });
    } else {
      plan.create.push(state.app);
      plan.warnings.push(`apps/${state.app} has no .env.example — ${where(state.app)} gets the generated/shared values only`);
      effective.set(state.app, {});
    }
  }

  const set = (app: AppName, key: string, value: string): void => {
    (plan.updates[app] ??= {})[key] = value;
    effective.get(app)![key] = value;
  };

  for (const rule of rules) {
    const targets = PRIORITY.filter((app) => rule.apps.includes(app) && effective.has(app)).filter((app) => {
      if (!rule.onlyIfDeclared) return true;
      const state = states.find((s) => s.app === app)!;
      return declares(state, rule.key);
    });
    if (targets.length === 0) continue;

    if (rule.shared) {
      const existing = targets.map((app) => ({ app, value: effective.get(app)![rule.key] })).filter((e) => usable(rule, e.value));
      const chosen = existing[0]?.value ?? rule.generate();
      if (existing.length === 0) plan.notes.push(`${rule.key} generated and shared by ${targets.join(', ')}`);
      for (const app of targets) {
        const current = effective.get(app)![rule.key];
        if (current === chosen) continue;
        if (usable(rule, current)) {
          plan.warnings.push(`${rule.key} in ${where(app)} differed from ${where(existing[0]!.app)}; aligned to ${where(existing[0]!.app)}`);
        } else if (existing.length > 0) {
          plan.notes.push(`${rule.key} copied into ${where(app)} from ${where(existing[0]!.app)}`);
        }
        set(app, rule.key, chosen);
      }
    } else {
      for (const app of targets) {
        if (usable(rule, effective.get(app)![rule.key])) continue;
        set(app, rule.key, rule.generate());
        plan.notes.push(`${rule.key} generated for ${where(app)}`);
      }
    }
  }

  for (const [app, values] of effective) {
    const identity: Record<string, string> = { PRODUCT_NAME: project.name, PRODUCT_SLUG: project.slug };
    for (const [key, value] of Object.entries(identity)) {
      const state = states.find((s) => s.app === app)!;
      if (declares(state, key) && isPlaceholder(values[key])) {
        set(app, key, value);
        plan.notes.push(`${key} set in ${where(app)} from scripts/project.env`);
      }
    }
    if (app === 'web' && (isPlaceholder(values.APP_NAME) || values.APP_NAME === 'Laravel')) {
      set(app, 'APP_NAME', project.name);
      plan.notes.push(`APP_NAME set in ${where('web')} from scripts/project.env`);
    }
  }

  plan.warnings.push(...checkInfraTargets(project, effective));
  return plan;
}

const NATIVE_PORTS: Record<number, string> = { 5432: 'native Postgres 16', 6379: 'native Redis 3' };
const LOCAL_HOST = '(?:localhost|127\\.0\\.0\\.1|\\[::1\\])';

export function checkInfraTargets(project: Project, envs: ReadonlyMap<AppName, EnvValues>): string[] {
  const warnings: string[] = [];
  for (const [app, values] of envs) {
    for (const [key, value] of Object.entries(values)) {
      const match = new RegExp(`(?:@|//)${LOCAL_HOST}:(\\d+)`).exec(value);
      const portValue = match ? Number(match[1]) : undefined;
      if (portValue !== undefined && NATIVE_PORTS[portValue]) {
        warnings.push(`apps/${app}: ${key} points at the ${NATIVE_PORTS[portValue]} (port ${portValue}); use the docker port from scripts/project.env`);
      }
    }
    if (app === 'web') {
      if (values.DB_CONNECTION && values.DB_CONNECTION !== 'pgsql') {
        warnings.push(`apps/web: DB_CONNECTION=${values.DB_CONNECTION}; the platform runs on Postgres (pgsql) only`);
      }
      if (values.DB_CONNECTION === 'pgsql' && values.DB_PORT && Number(values.DB_PORT) !== project.ports.postgres) {
        warnings.push(`apps/web: DB_PORT=${values.DB_PORT}, expected ${project.ports.postgres}`);
      }
      if (values.REDIS_PORT && values.REDIS_PORT !== 'null' && Number(values.REDIS_PORT) !== project.ports.redis) {
        warnings.push(`apps/web: REDIS_PORT=${values.REDIS_PORT}, expected ${project.ports.redis}`);
      }
    }
  }
  return warnings;
}
