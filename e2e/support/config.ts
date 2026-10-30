import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseEnvText, type EnvValues } from '../../scripts/lib/envfile.ts';
import { loadProject, type AppName, type Project } from '../../scripts/lib/project.ts';

export type E2EMode = 'managed' | 'reuse' | 'auto';

export interface E2EConfig {
  project: Project;
  e2eDir: string;
  artifactsDir: string;
  mode: E2EMode;
  baseURL: string;
  workerURL: string;
  aiURL: string;
  mailpitURL: string;
  database: { host: string; port: number; user: string; password: string; devName: string; e2eName: string };
  slug: string;
  credentials: { demo: string; admin: string; password: string };
  allowedHosts: string[];
}

export const E2E_DIR = join(dirname(fileURLToPath(import.meta.url)), '..');

export function readAppEnv(project: Project, app: AppName): EnvValues {
  for (const file of ['.env', '.env.example']) {
    const path = join(project.appDir(app), file);
    if (existsSync(path)) return parseEnvText(readFileSync(path, 'utf8'));
  }
  return {};
}

function parseMode(value: string | undefined, ci: boolean): E2EMode {
  if (value === 'managed' || value === 'reuse' || value === 'auto') return value;
  if (value) throw new Error(`E2E_MODE must be managed, reuse or auto (got "${value}")`);
  return ci ? 'managed' : 'auto';
}

const trimSlash = (url: string): string => url.replace(/\/+$/, '');

export function loadConfig(env: NodeJS.ProcessEnv = process.env): E2EConfig {
  const project = loadProject();
  const web = readAppEnv(project, 'web');
  const slug = web.PRODUCT_SLUG?.trim() || project.slug;
  const local = (port: number) => `http://127.0.0.1:${port}`;
  const workerURL = trimSlash(env.E2E_WORKER_URL ?? local(project.ports.worker));
  return {
    project,
    e2eDir: E2E_DIR,
    artifactsDir: join(E2E_DIR, 'artifacts'),
    mode: parseMode(env.E2E_MODE, Boolean(env.CI)),
    baseURL: trimSlash(env.E2E_BASE_URL || web.APP_URL || `http://localhost:${project.ports.web}`),
    workerURL,
    aiURL: trimSlash(env.E2E_AI_URL ?? workerURL),
    mailpitURL: trimSlash(env.E2E_MAILPIT_URL ?? local(project.ports.mailUi)),
    database: {
      host: env.E2E_DB_HOST ?? '127.0.0.1',
      port: Number(env.E2E_DB_PORT ?? project.ports.postgres),
      user: project.db.user,
      password: project.db.password,
      devName: project.db.name,
      e2eName: `${project.db.name}_e2e`,
    },
    slug,
    credentials: {
      demo: env.E2E_DEMO_EMAIL ?? `demo@${slug}.test`,
      admin: env.E2E_ADMIN_EMAIL ?? `admin@${slug}.test`,
      password: env.E2E_PASSWORD ?? 'password',
    },
    allowedHosts: (env.E2E_ALLOWED_HOSTS ?? '')
      .split(',')
      .map((host) => host.trim().toLowerCase())
      .filter(Boolean),
  };
}

export const RESOLVED_MODE_ENV = 'E2E_RESOLVED_MODE';
