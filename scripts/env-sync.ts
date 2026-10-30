import { copyFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { parseArgs } from 'node:util';
import { isPlaceholder, parseEnvText, upsertEnv } from './lib/envfile.ts';
import { planEnvSync, type AppEnvState, type EnvPlan } from './lib/env-plan.ts';
import { envTarget, type EnvTarget } from './lib/env-targets.ts';
import { log } from './lib/log.ts';
import { APP_NAMES, appPresent, loadProject, type AppName, type Project } from './lib/project.ts';
import { randomHex } from './lib/secrets.ts';

function readEnv(path: string) {
  return existsSync(path) ? parseEnvText(readFileSync(path, 'utf8')) : null;
}

function readState(target: EnvTarget, app: AppName): AppEnvState {
  const templatePath = target.templatePath(app);
  const template = existsSync(templatePath) ? target.derive(app, readFileSync(templatePath, 'utf8')) : null;
  return { app, example: template === null ? null : parseEnvText(template), current: readEnv(target.envPath(app)) };
}

export function collectStates(project: Project, target: EnvTarget = envTarget(project, 'dev')): AppEnvState[] {
  return APP_NAMES.filter((app) => appPresent(project, app)).map((app) => readState(target, app));
}

export function applyPlan(target: EnvTarget, plan: EnvPlan): void {
  for (const app of plan.create) {
    const destination = target.envPath(app);
    mkdirSync(dirname(destination), { recursive: true });
    const templatePath = target.templatePath(app);
    if (!existsSync(templatePath)) writeFileSync(destination, '', 'utf8');
    else if (target.name === 'dev') copyFileSync(templatePath, destination);
    else writeFileSync(destination, target.derive(app, readFileSync(templatePath, 'utf8')), 'utf8');
  }
  for (const [app, updates] of Object.entries(plan.updates) as Array<[AppName, Record<string, string>]>) {
    const file = target.envPath(app);
    const text = existsSync(file) ? readFileSync(file, 'utf8') : '';
    writeFileSync(file, upsertEnv(text, updates), 'utf8');
  }
}

function syncComposeEnv(project: Project, check: boolean): boolean {
  const file = join(project.root, 'infra', 'env', '.env.compose');
  const template = join(project.root, 'infra', 'env', '.env.compose.example');
  const exists = existsSync(file);
  const values = readEnv(exists ? file : template) ?? {};
  const updates: Record<string, string> = {};
  if (isPlaceholder(values.POSTGRES_PASSWORD)) updates.POSTGRES_PASSWORD = randomHex(24);
  for (const [key, value] of Object.entries({ COMPOSE_PROJECT: `${project.composeProject}-prod`, POSTGRES_DB: project.db.name })) {
    if (isPlaceholder(values[key])) updates[key] = value;
  }
  const pending = !exists || Object.keys(updates).length > 0;
  if (check) {
    if (pending) log.info('pending: infra/env/.env.compose');
    return pending;
  }
  const base = exists ? readFileSync(file, 'utf8') : existsSync(template) ? readFileSync(template, 'utf8') : '';
  writeFileSync(file, upsertEnv(base, updates), 'utf8');
  if (pending) log.ok('infra/env/.env.compose ready (POSTGRES_PASSWORD generated once)');
  return pending;
}

function main(): void {
  const { values } = parseArgs({
    options: { check: { type: 'boolean', default: false }, prod: { type: 'boolean', default: false } },
  });
  const project = loadProject();
  const target = envTarget(project, values.prod ? 'prod' : 'dev');
  const states = collectStates(project, target);
  if (states.length === 0) {
    log.warn('no apps found under apps/ — nothing to sync');
    return;
  }
  const where = (app: AppName) => relative(project.root, target.envPath(app)).split('\\').join('/');
  const plan = planEnvSync(project, states, undefined, where);
  const warnings = target.name === 'dev' ? plan.warnings : plan.warnings.filter((warning) => !/native|DB_PORT|REDIS_PORT|DB_CONNECTION/.test(warning));
  for (const warning of warnings) log.warn(warning);

  let pending = plan.create.length > 0 || Object.keys(plan.updates).length > 0;
  if (values.check) {
    for (const note of plan.notes) log.info(`pending: ${note}`);
    if (target.name === 'prod') pending = syncComposeEnv(project, true) || pending;
    if (pending) {
      log.error(`${target.name} env files are out of sync — run \`npm run env:sync${target.name === 'prod' ? ' -- --prod' : ''}\``);
      process.exitCode = 1;
    } else {
      log.ok(`${target.name} env files in sync`);
    }
    return;
  }

  applyPlan(target, plan);
  for (const note of plan.notes) log.ok(note);
  if (target.name === 'prod') pending = syncComposeEnv(project, false) || pending;
  if (!pending) log.ok(`${target.name} env files already in sync`);
}

if (import.meta.main) main();
