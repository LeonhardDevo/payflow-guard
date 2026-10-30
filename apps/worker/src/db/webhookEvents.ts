import type pg from 'pg';

export interface WebhookEventInput {
  provider: string;
  eventId: string;
  type: string;
  payload: unknown;
}

export interface RecordResult {
  inserted: boolean;
  processed: boolean;
}

export interface WebhookEventStore {
  record(input: WebhookEventInput): Promise<RecordResult>;
  markProcessed(provider: string, eventId: string): Promise<void>;
  markFailed(provider: string, eventId: string, error: string): Promise<void>;
  purgeOlderThan(days: number): Promise<number>;
}

export class PgWebhookEventStore implements WebhookEventStore {
  private readonly table: string;

  constructor(
    private readonly pool: pg.Pool,
    schema = 'worker',
  ) {
    if (!/^[a-z_][a-z0-9_]*$/.test(schema)) throw new Error(`Unsafe schema name: ${schema}`);
    this.table = `"${schema}".webhook_events`;
  }

  async record(input: WebhookEventInput): Promise<RecordResult> {
    const inserted = await this.pool.query<{ id: number }>(
      `INSERT INTO ${this.table} (provider, event_id, type, payload)
       VALUES ($1, $2, $3, $4::jsonb)
       ON CONFLICT (provider, event_id) DO NOTHING
       RETURNING id`,
      [input.provider, input.eventId, input.type, JSON.stringify(input.payload)],
    );
    if (inserted.rowCount === 1) return { inserted: true, processed: false };
    const existing = await this.pool.query<{ processed: boolean }>(
      `SELECT processed_at IS NOT NULL AS processed FROM ${this.table} WHERE provider = $1 AND event_id = $2`,
      [input.provider, input.eventId],
    );
    return { inserted: false, processed: existing.rows[0]?.processed ?? false };
  }

  async markProcessed(provider: string, eventId: string): Promise<void> {
    await this.pool.query(
      `UPDATE ${this.table}
          SET processed_at = COALESCE(processed_at, now()), attempts = attempts + 1, last_error = NULL
        WHERE provider = $1 AND event_id = $2`,
      [provider, eventId],
    );
  }

  async markFailed(provider: string, eventId: string, error: string): Promise<void> {
    await this.pool.query(
      `UPDATE ${this.table} SET attempts = attempts + 1, last_error = $3 WHERE provider = $1 AND event_id = $2`,
      [provider, eventId, error.slice(0, 2000)],
    );
  }

  async purgeOlderThan(days: number): Promise<number> {
    if (!Number.isInteger(days) || days < 1) throw new Error(`Invalid retention: ${days}`);
    const result = await this.pool.query(`DELETE FROM ${this.table} WHERE received_at < now() - make_interval(days => $1)`, [days]);
    return result.rowCount ?? 0;
  }
}

export class MemoryWebhookEventStore implements WebhookEventStore {
  readonly rows = new Map<string, { type: string; payload: unknown; processed: boolean; attempts: number; lastError: string | null; receivedAt: number }>();

  async record(input: WebhookEventInput): Promise<RecordResult> {
    const key = `${input.provider}:${input.eventId}`;
    const existing = this.rows.get(key);
    if (existing) return { inserted: false, processed: existing.processed };
    this.rows.set(key, { type: input.type, payload: input.payload, processed: false, attempts: 0, lastError: null, receivedAt: Date.now() });
    return { inserted: true, processed: false };
  }

  async markProcessed(provider: string, eventId: string): Promise<void> {
    const row = this.rows.get(`${provider}:${eventId}`);
    if (row) Object.assign(row, { processed: true, attempts: row.attempts + 1, lastError: null });
  }

  async markFailed(provider: string, eventId: string, error: string): Promise<void> {
    const row = this.rows.get(`${provider}:${eventId}`);
    if (row) Object.assign(row, { attempts: row.attempts + 1, lastError: error });
  }

  async purgeOlderThan(days: number): Promise<number> {
    const cutoff = Date.now() - days * 86_400_000;
    let removed = 0;
    for (const [key, row] of this.rows) {
      if (row.receivedAt < cutoff) {
        this.rows.delete(key);
        removed++;
      }
    }
    return removed;
  }
}
