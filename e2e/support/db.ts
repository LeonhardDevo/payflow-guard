import pg from 'pg';

export interface DbTarget {
  host: string;
  port: number;
  user: string;
  password: string;
}

export const E2E_DB_BOOTSTRAP_SQL = (owner: string): string[] => [
  'CREATE EXTENSION IF NOT EXISTS vector',
  'CREATE EXTENSION IF NOT EXISTS pg_trgm',
  'CREATE EXTENSION IF NOT EXISTS unaccent',
  `CREATE SCHEMA IF NOT EXISTS rag AUTHORIZATION ${quoteIdent(owner)}`,
  `CREATE SCHEMA IF NOT EXISTS worker AUTHORIZATION ${quoteIdent(owner)}`,
];

export function quoteIdent(identifier: string): string {
  return `"${identifier.replace(/"/g, '""')}"`;
}

export function assertDisposable(database: string): void {
  if (!/^[a-z_][a-z0-9_]*_e2e$/.test(database)) {
    throw new Error(`refusing to drop "${database}": only <db>_e2e databases are recreated by the e2e suite`);
  }
}

export async function canConnect(target: DbTarget): Promise<boolean> {
  const client = new pg.Client({ ...target, database: 'postgres', connectionTimeoutMillis: 2_000 });
  try {
    await client.connect();
    return true;
  } catch {
    return false;
  } finally {
    await client.end().catch(() => undefined);
  }
}

export async function recreateDatabase(target: DbTarget, database: string): Promise<void> {
  assertDisposable(database);
  const admin = new pg.Client({ ...target, database: 'postgres', connectionTimeoutMillis: 5_000 });
  await admin.connect();
  try {
    await admin.query(`DROP DATABASE IF EXISTS ${quoteIdent(database)} WITH (FORCE)`);
    await admin.query(`CREATE DATABASE ${quoteIdent(database)} OWNER ${quoteIdent(target.user)}`);
  } finally {
    await admin.end();
  }

  const client = new pg.Client({ ...target, database, connectionTimeoutMillis: 5_000 });
  await client.connect();
  try {
    for (const statement of E2E_DB_BOOTSTRAP_SQL(target.user)) await client.query(statement);
  } finally {
    await client.end();
  }
}

export interface SubscriptionRow {
  workspace_id: number;
  stripe_subscription_id: string;
  stripe_customer_id: string;
  status: string;
}

export async function subscriptionForOwner(target: DbTarget, database: string, email: string): Promise<SubscriptionRow | null> {
  const client = new pg.Client({ ...target, database, connectionTimeoutMillis: 5_000 });
  await client.connect();
  try {
    const result = await client.query<SubscriptionRow>(
      `SELECT s.workspace_id, s.stripe_subscription_id, s.stripe_customer_id, s.status
         FROM subscriptions s
         JOIN workspace_user wu ON wu.workspace_id = s.workspace_id
         JOIN users u ON u.id = wu.user_id
        WHERE u.email = $1 AND wu.role = 'owner'
        ORDER BY s.id DESC
        LIMIT 1`,
      [email],
    );
    return result.rows[0] ?? null;
  } finally {
    await client.end();
  }
}
