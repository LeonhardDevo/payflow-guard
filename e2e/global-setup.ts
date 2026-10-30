import { existsSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import type { FullConfig } from '@playwright/test';
import { parseEnvText, upsertEnv } from '../scripts/lib/envfile.ts';
import { isPortOpen, isWindows } from '../scripts/lib/proc.ts';
import { appPresent, npmScripts } from '../scripts/lib/project.ts';
import { loadConfig, readAppEnv, RESOLVED_MODE_ENV, type E2EConfig } from './support/config.ts';
import { canConnect, recreateDatabase } from './support/db.ts';
import { Mailpit } from './support/mailpit.ts';
import {
  e2eOverrides,
  isHealthy,
  runStep,
  startService,
  waitForHealthy,
  type E2EContext,
  type ProcessSpec,
  type RunningService,
} from './support/services.ts';

const say = (message: string) => console.log(`[e2e] ${message}`);

function healthUrls(cfg: E2EConfig) {
  return { web: `${cfg.baseURL}/up`, worker: `${cfg.workerURL}/healthz`, rag: `${cfg.aiURL}/healthz` };
}

async function ensureInfra(cfg: E2EConfig, logDir: string): Promise<void> {
  const target = { host: cfg.database.host, port: cfg.database.port, user: cfg.database.user, password: cfg.database.password };
  const mailpit = new Mailpit(cfg.mailpitURL);
  if ((await canConnect(target)) && (await mailpit.isUp()) && (await isPortOpen(cfg.project.ports.redis))) return;
  if (process.env.CI) throw new Error('postgres / redis / mailpit are not reachable — check the CI service containers');
  say('infra not reachable — running docker compose up -d --wait');
  await runStep({ name: 'docker-compose', cwd: cfg.project.root, command: 'docker', args: ['compose', '--env-file', join(cfg.project.root, 'scripts', 'project.env'), 'up', '-d', '--wait', '--wait-timeout', '180'] }, logDir);
  if (!(await canConnect(target))) throw new Error(`postgres on ${cfg.database.host}:${cfg.database.port} still not reachable`);
}

function writeWebEnv(cfg: E2EConfig, ctx: E2EContext): void {
  const dir = cfg.project.appDir('web');
  const source = ['.env', '.env.example'].map((file) => join(dir, file)).find((file) => existsSync(file));
  const text = source ? readFileSync(source, 'utf8') : '';
  const overrides = e2eOverrides('web', parseEnvText(text), ctx);
  writeFileSync(join(dir, '.env.e2e'), upsertEnv(text, overrides), 'utf8');
}

async function prepareWebAssets(cfg: E2EConfig, logDir: string): Promise<void> {
  const web = cfg.project.appDir('web');
  const hot = join(web, 'public', 'hot');
  if (existsSync(hot) && !(await isPortOpen(cfg.project.ports.vite))) {
    say('removing stale public/hot (vite is not running)');
    rmSync(hot);
  }
  if (!existsSync(join(web, 'public', 'build', 'manifest.json')) && !existsSync(hot)) {
    say('building web assets (npm run build)…');
    await runStep({ name: 'web-build', cwd: web, command: 'npm', args: ['run', 'build'], shell: isWindows }, logDir, 600_000);
  }
}

async function startManaged(cfg: E2EConfig): Promise<() => Promise<void>> {
  const logDir = join(cfg.artifactsDir, 'logs');
  const { ports } = cfg.project;
  const busy = [];
  for (const [name, port] of [
    ['web', ports.web],
    ['web-internal', ports.webInternal],
    ['worker', ports.worker],
  ] as const) {
    if (port === undefined) continue;
    if (await isPortOpen(port)) busy.push(`${name} :${port}`);
  }
  if (busy.length) {
    throw new Error(
      `managed e2e needs free ports but ${busy.join(', ')} are in use.\n` +
        'Stop the dev servers (`npm run stop -- --keep-infra`) or run against them with E2E_MODE=reuse.',
    );
  }
  for (const app of ['web', 'worker'] as const) {
    if (!appPresent(cfg.project, app)) throw new Error(`apps/${app} is missing — the e2e suite needs both services`);
  }
  if (!existsSync(join(cfg.project.appDir('web'), 'vendor', 'autoload.php'))) throw new Error('apps/web/vendor missing — run `npm run setup`');

  await ensureInfra(cfg, logDir);

  const ctx: E2EContext = {
    devDb: cfg.database.devName,
    e2eDb: cfg.database.e2eName,
    baseURL: cfg.baseURL,
    workerURL: cfg.workerURL,
    aiURL: cfg.aiURL,
    slug: cfg.slug,
    ...(ports.webInternal ? { webInternalURL: `http://127.0.0.1:${ports.webInternal}` } : {}),
  };
  const webDir = cfg.project.appDir('web');
  const workerDir = cfg.project.appDir('worker');
  const webEnv = { APP_ENV: 'e2e' };
  const workerEnv = e2eOverrides('worker', readAppEnv(cfg.project, 'worker'), ctx);

  say(`recreating database ${ctx.e2eDb}`);
  await recreateDatabase(
    { host: cfg.database.host, port: cfg.database.port, user: cfg.database.user, password: cfg.database.password },
    ctx.e2eDb,
  );

  writeWebEnv(cfg, ctx);
  await prepareWebAssets(cfg, logDir);

  say('migrating + seeding (web → worker)');
  await runStep({ name: 'web-config-clear', cwd: webDir, command: 'php', args: ['artisan', 'config:clear'], env: webEnv }, logDir);
  await runStep({ name: 'web-migrate', cwd: webDir, command: 'php', args: ['artisan', 'migrate', '--seed', '--force'], env: webEnv }, logDir);
  if (npmScripts(workerDir).has('migrate')) {
    await runStep({ name: 'worker-migrate', cwd: workerDir, command: 'npm', args: ['run', 'migrate'], env: workerEnv, shell: isWindows }, logDir);
  }

  const specs: ProcessSpec[] = [
    {
      name: 'web',
      cwd: webDir,
      command: 'php',
      args: ['artisan', 'serve', '--host=127.0.0.1', `--port=${ports.web}`, '--no-reload'],
      env: { ...webEnv, ...(isWindows ? {} : { PHP_CLI_SERVER_WORKERS: '4' }) },
    },
    ...(ports.webInternal
      ? [
          {
            name: 'web-internal',
            cwd: webDir,
            command: 'php',
            args: ['artisan', 'serve', '--host=127.0.0.1', `--port=${ports.webInternal}`, '--no-reload'],
            env: webEnv,
          },
        ]
      : []),
    {
      name: 'worker',
      cwd: workerDir,
      command: process.execPath,
      args: existsSync(join(workerDir, 'src', 'index.ts')) ? ['--import', 'tsx', 'src/index.ts'] : ['dist/index.js'],
      env: workerEnv,
    },
  ];
  const queueConnection = parseEnvText(readFileSync(join(webDir, '.env.e2e'), 'utf8')).QUEUE_CONNECTION ?? 'sync';
  if (queueConnection !== 'sync') {
    specs.push({ name: 'web-queue', cwd: webDir, command: 'php', args: ['artisan', 'queue:work', '--sleep=1', '--tries=3'], env: webEnv });
  }

  const running: RunningService[] = [];
  const stopAll = async () => {
    for (const service of running.reverse()) service.stop();
    running.length = 0;
  };
  process.once('exit', () => void stopAll());

  try {
    for (const spec of specs) running.push(startService(spec, logDir));
    const urls = healthUrls(cfg);
    await Promise.all([
      waitForHealthy(urls.web, running.find((s) => s.name === 'web')!),
      waitForHealthy(urls.worker, running.find((s) => s.name === 'worker')!),
      ...(ctx.webInternalURL ? [waitForHealthy(`${ctx.webInternalURL}/up`, running.find((s) => s.name === 'web-internal')!)] : []),
    ]);
    await waitForHealthy(urls.rag, running.find((s) => s.name === 'worker')!);
  } catch (error) {
    await stopAll();
    throw error;
  }
  say(`managed services healthy — logs in ${logDir}`);
  return stopAll;
}

export default async function globalSetup(_config: FullConfig): Promise<() => Promise<void>> {
  const cfg = loadConfig();
  const urls = healthUrls(cfg);
  const healthy = await Promise.all([isHealthy(urls.web), isHealthy(urls.worker), isHealthy(urls.rag)]);
  const mode = cfg.mode === 'auto' ? (healthy.every(Boolean) ? 'reuse' : 'managed') : cfg.mode;
  process.env[RESOLVED_MODE_ENV] = mode;

  if (mode === 'reuse') {
    const down = Object.entries(urls)
      .filter((_, index) => !healthy[index])
      .map(([name, url]) => `${name} (${url})`);
    if (down.length) throw new Error(`E2E_MODE=reuse but these services are not healthy: ${down.join(', ')}`);
    if (!(await new Mailpit(cfg.mailpitURL).isUp())) throw new Error(`Mailpit is not reachable on ${cfg.mailpitURL}`);
    say(`reusing running services at ${cfg.baseURL} (their own database — specs create unique data)`);
    return async () => undefined;
  }
  say(`managed mode: isolated database ${cfg.database.e2eName}, base URL ${cfg.baseURL}`);
  return startManaged(cfg);
}
