import { spawn, type ChildProcess } from 'node:child_process';
import { closeSync, existsSync, mkdirSync, openSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import type { EnvValues } from '../../scripts/lib/envfile.ts';
import type { AppName } from '../../scripts/lib/project.ts';
import { commandLine, isWindows, killTree } from '../../scripts/lib/proc.ts';

export interface E2EContext {
  devDb: string;
  e2eDb: string;
  baseURL: string;
  workerURL: string;
  aiURL: string;
  slug: string;
  webInternalURL?: string;
}

const POSTGRES_URL = /^(postgres(?:ql)?(?:\+[a-z0-9]+)?:\/\/[^/]*\/)([^?#]+)(.*)$/i;

export function rewriteDatabaseUrl(value: string, from: string, to: string): string | null {
  const match = POSTGRES_URL.exec(value.trim());
  if (!match || decodeURIComponent(match[2]!) !== from) return null;
  return `${match[1]}${to}${match[3]}`;
}

function databaseUrlOverrides(env: EnvValues, ctx: E2EContext): Record<string, string> {
  const overrides: Record<string, string> = {};
  for (const [key, value] of Object.entries(env)) {
    const rewritten = rewriteDatabaseUrl(value, ctx.devDb, ctx.e2eDb);
    if (rewritten) overrides[key] = rewritten;
  }
  return overrides;
}

export const AI_OFFLINE_ENV: Readonly<Record<string, string>> = {
  EMBEDDINGS_PROVIDER: 'hash',
  AI_PROVIDER: 'mock',
};

export function stripeLiveKey(env: NodeJS.ProcessEnv = process.env): string | null {
  const key = env.STRIPE_SECRET_KEY ?? '';

  return env.E2E_STRIPE_LIVE === '1' && key.startsWith('sk_test_') ? key : null;
}

export function e2eOverrides(app: AppName, env: EnvValues, ctx: E2EContext): Record<string, string> {
  const db = databaseUrlOverrides(env, ctx);
  switch (app) {
    case 'web':
      return {
        ...db,
        APP_ENV: 'e2e',
        APP_DEBUG: 'true',
        APP_URL: ctx.baseURL,
        DB_DATABASE: ctx.e2eDb,
        REDIS_PREFIX: `${ctx.slug}-e2e-`,
        CACHE_PREFIX: `${ctx.slug}-e2e-cache-`,
        BILLING_DRIVER: stripeLiveKey() ? 'stripe' : 'fake',
        ...(stripeLiveKey() ? { STRIPE_SECRET: stripeLiveKey()! } : {}),
        WORKER_URL: ctx.workerURL,
        AI_URL: ctx.aiURL,
        DEV_ROUTES: '1',
        TELEGRAM_MODE: 'disabled',
        PAYFLOW_ALLOW_FAKE_PAYMENTS: 'true',
      };
    case 'worker':
      return {
        ...db,
        ...AI_OFFLINE_ENV,
        NODE_ENV: 'development',
        WORKER_ROLES: 'all',
        DEV_ROUTES: '1',
        QUEUE_PREFIX: `${ctx.slug}-e2e`,
        WEB_URL: ctx.webInternalURL ?? ctx.baseURL,
        WEB_PUBLIC_URL: ctx.baseURL,
        AI_URL: ctx.aiURL,
        TELEGRAM_MODE: 'disabled',
        TELEGRAM_BOT_TOKEN: '',
        STRIPE_SECRET_KEY: stripeLiveKey() ?? '',
        LOG_PRETTY: '0',
      };
  }
}

export interface ProcessSpec {
  name: string;
  cwd: string;
  command: string;
  args: string[];
  env?: Record<string, string>;
  shell?: boolean;
}

export interface RunningService {
  name: string;
  child: ChildProcess;
  logFile: string;
  stop: () => void;
}

function openLog(logDir: string, name: string): { path: string; fd: number } {
  mkdirSync(logDir, { recursive: true });
  const path = join(logDir, `${name}.log`);
  return { path, fd: openSync(path, 'a') };
}

export function tail(file: string, lines = 40): string {
  if (!existsSync(file)) return '(no log)';
  return readFileSync(file, 'utf8').split(/\r?\n/).slice(-lines).join('\n');
}

function spawnLogged(spec: ProcessSpec, logDir: string): { child: ChildProcess; logFile: string } {
  const log = openLog(logDir, spec.name);
  const options = {
    cwd: spec.cwd,
    env: { ...process.env, ...spec.env },
    stdio: ['ignore', log.fd, log.fd] as ['ignore', number, number],
    detached: !isWindows,
    windowsHide: true,
  };
  const child = spec.shell
    ? spawn(commandLine(spec.command, spec.args), { ...options, shell: true })
    : spawn(spec.command, spec.args, options);
  child.once('exit', () => closeSync(log.fd));
  return { child, logFile: log.path };
}

export function runStep(spec: ProcessSpec, logDir: string, timeoutMs = 300_000): Promise<void> {
  return new Promise((resolve, reject) => {
    const { child, logFile } = spawnLogged(spec, logDir);
    const timer = setTimeout(() => {
      if (child.pid) killTree(child.pid);
      reject(new Error(`${spec.name} timed out after ${timeoutMs} ms\n${tail(logFile)}`));
    }, timeoutMs);
    child.once('error', (error) => {
      clearTimeout(timer);
      reject(error);
    });
    child.once('exit', (code) => {
      clearTimeout(timer);
      if (code === 0) resolve();
      else reject(new Error(`${spec.name} failed (exit ${code}) — ${logFile}\n${tail(logFile)}`));
    });
  });
}

export function startService(spec: ProcessSpec, logDir: string): RunningService {
  const { child, logFile } = spawnLogged(spec, logDir);
  return {
    name: spec.name,
    child,
    logFile,
    stop: () => {
      if (child.pid && child.exitCode === null) killTree(child.pid);
    },
  };
}

export async function waitForHealthy(url: string, service: RunningService | null, timeoutMs = 120_000): Promise<void> {
  const deadline = Date.now() + timeoutMs;
  let lastError = '';
  while (Date.now() < deadline) {
    if (service && service.child.exitCode !== null) {
      throw new Error(`${service.name} exited with code ${service.child.exitCode} before becoming healthy\n${tail(service.logFile)}`);
    }
    try {
      const response = await fetch(url, { signal: AbortSignal.timeout(3_000), redirect: 'manual' });
      if (response.status < 400) return;
      lastError = `HTTP ${response.status}`;
    } catch (error) {
      lastError = error instanceof Error ? error.message : String(error);
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  throw new Error(`${url} not healthy after ${timeoutMs} ms (${lastError})${service ? `\n${tail(service.logFile)}` : ''}`);
}

export async function isHealthy(url: string): Promise<boolean> {
  try {
    const response = await fetch(url, { signal: AbortSignal.timeout(2_000), redirect: 'manual' });
    return response.status < 400;
  } catch {
    return false;
  }
}
