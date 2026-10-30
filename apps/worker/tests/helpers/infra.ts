import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import pg from 'pg';
import { Redis } from 'ioredis';
import { redisOptionsFromUrl } from '../../src/queues/redis.js';

const root = resolve(import.meta.dirname, '..', '..');

function readEnvFile(): Record<string, string> {
  for (const name of ['.env', '.env.example']) {
    const file = resolve(root, name);
    if (!existsSync(file)) continue;
    const values: Record<string, string> = {};
    for (const line of readFileSync(file, 'utf8').split(/\r?\n/)) {
      const match = /^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/.exec(line);
      if (match?.[1]) values[match[1]] = (match[2] ?? '').replace(/^"(.*)"$/, '$1');
    }
    return values;
  }
  return {};
}

const fileEnv = readEnvFile();

export function fileEnvValue(key: string): string | undefined {
  const value = process.env[key] ?? fileEnv[key];
  return value === undefined || value.trim() === '' ? undefined : value.trim();
}

export function testDatabaseUrl(): string | null {
  if (process.env.TEST_DATABASE_URL) return process.env.TEST_DATABASE_URL;
  const base = fileEnv.DATABASE_URL;
  if (!base) return null;
  const url = new URL(base);
  const db = url.pathname.replace(/^\//, '');
  url.pathname = `/${db.endsWith('_test') ? db : `${db}_test`}`;
  return url.toString();
}

export function testRedisUrl(): string | null {
  return process.env.TEST_REDIS_URL ?? fileEnv.REDIS_URL ?? null;
}

export async function postgresAvailable(): Promise<string | null> {
  const url = testDatabaseUrl();
  if (!url) return null;
  const client = new pg.Client({ connectionString: url, connectionTimeoutMillis: 2000 });
  try {
    await client.connect();
    await client.query('SELECT 1');
    return url;
  } catch {
    return null;
  } finally {
    await client.end().catch(() => undefined);
  }
}

export async function redisAvailable(): Promise<string | null> {
  const url = testRedisUrl();
  if (!url) return null;
  const redis = new Redis({ ...redisOptionsFromUrl(url), lazyConnect: true, maxRetriesPerRequest: 1, retryStrategy: () => null, connectTimeout: 2000 });
  redis.on('error', () => undefined);
  try {
    await redis.connect();
    await redis.ping();
    return url;
  } catch {
    return null;
  } finally {
    redis.disconnect();
  }
}

export function skipNotice(service: string, url: string | null): string {
  return `[skip] ${service} not reachable${url ? ` at ${url}` : ''} — start it with "docker compose up -d" in the project root to run these tests`;
}

export function announceSkip(service: string, url: string | null): string {
  const notice = skipNotice(service, url);
  process.stderr.write(`\n${notice}\n`);
  return ` — SKIPPED: ${service} not reachable`;
}

export function uniqueSuffix(): string {
  return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
}
