import type pg from 'pg';
import { quoteQualified } from '../db/pool.js';
import type { OutboxRow } from './types.js';

export const MAX_RETRY_DELAY_S = 3600;

export class OutboxRepository {
  private readonly table: string;

  constructor(table: string) {
    this.table = quoteQualified(table);
  }

  async claim(client: pg.PoolClient, limit: number): Promise<OutboxRow[]> {
    const { rows } = await client.query<OutboxRow>(
      `SELECT id::text AS id, topic, payload, workspace_id, attempts
         FROM ${this.table}
        WHERE dispatched_at IS NULL AND available_at <= now()
        ORDER BY available_at, created_at
        LIMIT $1
        FOR UPDATE SKIP LOCKED`,
      [limit],
    );
    return rows;
  }

  async markDispatched(client: pg.PoolClient, ids: string[]): Promise<void> {
    if (ids.length === 0) return;
    await client.query(
      `UPDATE ${this.table}
          SET dispatched_at = now(), attempts = attempts + 1, last_error = NULL
        WHERE id = ANY($1::uuid[])`,
      [ids],
    );
  }

  async markFailed(client: pg.PoolClient, id: string, error: string): Promise<void> {
    await client.query(
      `UPDATE ${this.table}
          SET attempts = attempts + 1,
              last_error = $2,
              available_at = now() + LEAST($3::int, power(2, LEAST(attempts + 1, 16))::int) * interval '1 second'
        WHERE id = $1::uuid`,
      [id, error.slice(0, 2000), MAX_RETRY_DELAY_S],
    );
  }
}
