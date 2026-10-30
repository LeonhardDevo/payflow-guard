import type pg from 'pg';
import type { Logger } from '../logger.js';
import { migrations as defaultMigrations, type Migration } from './migrations.js';

const MIGRATION_LOCK_KEY = 0x776f726b;

export async function migrate(
  pool: pg.Pool,
  logger: Logger,
  options: { schema?: string; migrations?: readonly Migration[] } = {},
): Promise<string[]> {
  const schema = options.schema ?? 'worker';
  if (!/^[a-z_][a-z0-9_]*$/.test(schema)) throw new Error(`Unsafe schema name: ${schema}`);
  const list = options.migrations ?? defaultMigrations;
  const client = await pool.connect();
  const applied: string[] = [];
  try {
    await client.query('BEGIN');
    await client.query('SELECT pg_advisory_xact_lock($1)', [MIGRATION_LOCK_KEY]);
    await client.query(`CREATE SCHEMA IF NOT EXISTS "${schema}"`);
    await client.query(`SET LOCAL search_path TO "${schema}", public`);
    await client.query(
      `CREATE TABLE IF NOT EXISTS "${schema}".schema_migrations (
         version    varchar(255) PRIMARY KEY,
         applied_at timestamptz NOT NULL DEFAULT now()
       )`,
    );
    const { rows } = await client.query<{ version: string }>(`SELECT version FROM "${schema}".schema_migrations`);
    const done = new Set(rows.map((row) => row.version));
    for (const migration of list) {
      if (done.has(migration.version)) continue;
      const sql = schema === 'worker' ? migration.sql : migration.sql.replaceAll('worker.', `"${schema}".`);
      await client.query(sql);
      await client.query(`INSERT INTO "${schema}".schema_migrations (version) VALUES ($1)`, [migration.version]);
      applied.push(migration.version);
    }
    await client.query('COMMIT');
  } catch (error) {
    await client.query('ROLLBACK').catch(() => undefined);
    throw error;
  } finally {
    client.release();
  }
  if (applied.length > 0) logger.info({ schema, applied }, 'worker migrations applied');
  return applied;
}
