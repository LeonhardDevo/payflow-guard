import pg from 'pg';
import type { Logger } from '../logger.js';

export type Pool = pg.Pool;
export type PoolClient = pg.PoolClient;

pg.types.setTypeParser(pg.types.builtins.INT8, (value) => Number(value));

export function createPool(connectionString: string, max: number, logger: Logger): pg.Pool {
  const pool = new pg.Pool({
    connectionString,
    max,
    idleTimeoutMillis: 30_000,
    connectionTimeoutMillis: 5_000,
    application_name: 'worker',
  });
  pool.on('error', (error) => logger.error({ err: error }, 'postgres pool client error'));
  return pool;
}

export async function withTransaction<T>(pool: pg.Pool, fn: (client: pg.PoolClient) => Promise<T>): Promise<T> {
  const client = await pool.connect();
  try {
    await client.query('BEGIN');
    const result = await fn(client);
    await client.query('COMMIT');
    return result;
  } catch (error) {
    await client.query('ROLLBACK').catch(() => undefined);
    throw error;
  } finally {
    client.release();
  }
}

export function quoteQualified(name: string): string {
  const parts = name.split('.');
  if (parts.length !== 2 || parts.some((part) => !/^[a-z_][a-z0-9_]*$/.test(part))) {
    throw new Error(`Unsafe SQL identifier: ${name}`);
  }
  return parts.map((part) => `"${part}"`).join('.');
}

export function isUndefinedTable(error: unknown): boolean {
  return (error as { code?: string } | null)?.code === '42P01';
}
