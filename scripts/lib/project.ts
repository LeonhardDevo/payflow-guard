import { existsSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseEnv } from 'node:util';

export type AppName = 'web' | 'worker';

export const APP_NAMES: readonly AppName[] = ['web', 'worker'];

export interface Ports {
  web: number;
  vite: number;
  worker: number;
  postgres: number;
  redis: number;
  mailSmtp: number;
  mailUi: number;
  webInternal?: number;
}

export interface Project {
  root: string;
  name: string;
  slug: string;
  composeProject: string;
  db: { name: string; user: string; password: string };
  ports: Ports;
  appDir: (app: AppName) => string;
}

const REQUIRED_KEYS = [
  'PRODUCT_NAME',
  'PRODUCT_SLUG',
  'COMPOSE_PROJECT',
  'DB_NAME',
  'DB_USER',
  'DB_PASSWORD',
  'WEB_PORT',
  'VITE_PORT',
  'WORKER_PORT',
  'PG_PORT',
  'REDIS_PORT',
  'MAIL_SMTP_PORT',
  'MAIL_UI_PORT',
] as const;

export class ProjectConfigError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'ProjectConfigError';
  }
}

export function repoRoot(): string {
  return resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
}

function port(values: Record<string, string | undefined>, key: string): number {
  const raw = values[key];
  const value = Number(raw);
  if (!Number.isInteger(value) || value < 1 || value > 65535) {
    throw new ProjectConfigError(`scripts/project.env: ${key} must be a TCP port, got "${raw ?? ''}"`);
  }
  return value;
}

export function parseProject(text: string, root: string): Project {
  const values = parseEnv(text) as Record<string, string | undefined>;
  const missing = REQUIRED_KEYS.filter((key) => !values[key] || values[key]?.trim() === '');
  if (missing.length > 0) {
    throw new ProjectConfigError(`scripts/project.env is missing: ${missing.join(', ')}`);
  }
  const slug = values.PRODUCT_SLUG!.trim();
  if (!/^[a-z0-9][a-z0-9-]{0,40}$/.test(slug)) {
    throw new ProjectConfigError('scripts/project.env: PRODUCT_SLUG must be lowercase letters, digits and dashes');
  }
  if (!/^[a-z_][a-z0-9_]{0,40}$/.test(values.DB_NAME!)) {
    throw new ProjectConfigError('scripts/project.env: DB_NAME must be a plain lowercase identifier');
  }

  const ports: Ports = {
    web: port(values, 'WEB_PORT'),
    vite: port(values, 'VITE_PORT'),
    worker: port(values, 'WORKER_PORT'),
    postgres: port(values, 'PG_PORT'),
    redis: port(values, 'REDIS_PORT'),
    mailSmtp: port(values, 'MAIL_SMTP_PORT'),
    mailUi: port(values, 'MAIL_UI_PORT'),
    ...(values.WEB_INTERNAL_PORT?.trim() ? { webInternal: port(values, 'WEB_INTERNAL_PORT') } : {}),
  };
  const seen = new Map<number, string>();
  for (const [key, value] of Object.entries(ports)) {
    const clash = seen.get(value);
    if (clash) throw new ProjectConfigError(`scripts/project.env: ports ${clash} and ${key} are both ${value}`);
    seen.set(value, key);
  }

  return {
    root,
    name: values.PRODUCT_NAME!.trim(),
    slug,
    composeProject: values.COMPOSE_PROJECT!.trim(),
    db: { name: values.DB_NAME!.trim(), user: values.DB_USER!.trim(), password: values.DB_PASSWORD! },
    ports,
    appDir: (app: AppName) => join(root, 'apps', app),
  };
}

export function loadProject(root: string = repoRoot()): Project {
  const file = join(root, 'scripts', 'project.env');
  if (!existsSync(file)) throw new ProjectConfigError(`Missing ${file}`);
  return parseProject(readFileSync(file, 'utf8'), root);
}

export function appPresent(project: Project, app: AppName): boolean {
  const manifest = { web: 'composer.json', worker: 'package.json' }[app];
  return existsSync(join(project.appDir(app), manifest));
}

export function npmScripts(dir: string): Set<string> {
  const file = join(dir, 'package.json');
  if (!existsSync(file)) return new Set();
  try {
    const pkg = JSON.parse(readFileSync(file, 'utf8')) as { scripts?: Record<string, string> };
    return new Set(Object.keys(pkg.scripts ?? {}));
  } catch {
    return new Set();
  }
}
