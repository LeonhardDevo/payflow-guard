import type pg from 'pg';

export class RagDb {
  readonly schema: string;

  constructor(
    readonly pool: pg.Pool,
    schema: string,
  ) {
    if (!/^[a-z_][a-z0-9_]{0,62}$/.test(schema)) throw new Error(`Unsafe schema name: ${schema}`);
    this.schema = schema;
  }

  table(name: 'documents' | 'chunks' | 'settings' | 'ai_usage' | 'conversation_turns'): string {
    return `"${this.schema}".${name}`;
  }

  get unaccent(): string {
    return `"${this.schema}".f_unaccent`;
  }

  async query<R extends pg.QueryResultRow = pg.QueryResultRow>(sql: string, params: unknown[] = []): Promise<pg.QueryResult<R>> {
    return this.pool.query<R>(sql, params);
  }

  async transaction<T>(fn: (client: pg.PoolClient) => Promise<T>): Promise<T> {
    const client = await this.pool.connect();
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
}
