import { setTimeout as sleep } from 'node:timers/promises';
import pg from 'pg';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { createSilentLogger } from '../src/logger.js';
import { OutboxRelay } from '../src/outbox/relay.js';
import { MemoryEnqueuer, type JobEnqueuer } from '../src/queues/enqueuer.js';
import type { QueueName } from '../src/queues/names.js';
import { postgresAvailable, announceSkip, testDatabaseUrl, uniqueSuffix } from './helpers/infra.js';

const databaseUrl = await postgresAvailable();
const skipSuffix = databaseUrl ? '' : announceSkip('Postgres (outbox relay tests)', testDatabaseUrl());

const schema = `outbox_t_${uniqueSuffix()}`;
const table = `${schema}.outbox_messages`;
const channel = `${schema}_ch`;

describe.skipIf(!databaseUrl)(`outbox relay (real Postgres)${skipSuffix}`, () => {
  let pool: pg.Pool;

  const relay = (enqueuer: JobEnqueuer, overrides: Partial<ConstructorParameters<typeof OutboxRelay>[0]> = {}) =>
    new OutboxRelay({ pool, enqueuer, logger: createSilentLogger(), table, channel, pollMs: 60_000, batchSize: 100, ...overrides });

  async function insert(topic: string, payload: unknown, options: { availableInS?: number; workspaceId?: number | null } = {}): Promise<string> {
    const { rows } = await pool.query<{ id: string }>(
      `INSERT INTO ${table} (topic, payload, workspace_id, available_at)
       VALUES ($1, $2::jsonb, $3, now() + make_interval(secs => $4)) RETURNING id::text`,
      [topic, JSON.stringify(payload), options.workspaceId ?? 3, options.availableInS ?? 0],
    );
    return rows[0]!.id;
  }

  async function row(id: string) {
    const { rows } = await pool.query<{ dispatched: boolean; attempts: number; last_error: string | null; delay_s: number }>(
      `SELECT dispatched_at IS NOT NULL AS dispatched, attempts, last_error,
              EXTRACT(EPOCH FROM (available_at - now()))::float AS delay_s
         FROM ${table} WHERE id = $1`,
      [id],
    );
    return rows[0]!;
  }

  beforeAll(async () => {
    pool = new pg.Pool({ connectionString: databaseUrl!, max: 8 });
    await pool.query(`CREATE SCHEMA "${schema}"`);
    await pool.query(`
      CREATE TABLE ${table} (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        topic varchar(120) NOT NULL,
        payload jsonb NOT NULL,
        workspace_id bigint NULL,
        available_at timestamptz NOT NULL DEFAULT now(),
        dispatched_at timestamptz NULL,
        attempts int NOT NULL DEFAULT 0,
        last_error text NULL,
        created_at timestamptz NOT NULL DEFAULT now()
      );
      CREATE INDEX ON ${table} (available_at) WHERE dispatched_at IS NULL;
    `);
  });

  beforeEach(async () => {
    await pool.query(`TRUNCATE ${table}`);
  });

  afterAll(async () => {
    await pool.query(`DROP SCHEMA IF EXISTS "${schema}" CASCADE`);
    await pool.end();
  });

  it('claims due rows, enqueues with jobId = outbox id and marks them dispatched', async () => {
    const email = await insert('email.send', { to: 'a@b.c' });
    const doc = await insert('document.uploaded', { document_id: 1, workspace_id: 3 }, { workspaceId: 3 });
    const enqueuer = new MemoryEnqueuer();

    const result = await relay(enqueuer).drain();

    expect(result).toEqual({ claimed: 2, dispatched: 2, failed: 0 });
    expect(enqueuer.jobs).toEqual(
      expect.arrayContaining([
        { queue: 'emails', name: 'email.send', jobId: email, data: { outbox_id: email, topic: 'email.send', workspace_id: 3, payload: { to: 'a@b.c' } } },
        {
          queue: 'documents',
          name: 'document.uploaded',
          jobId: doc,
          data: { outbox_id: doc, topic: 'document.uploaded', workspace_id: 3, payload: { document_id: 1, workspace_id: 3 } },
        },
      ]),
    );
    expect(await row(email)).toMatchObject({ dispatched: true, attempts: 1, last_error: null });
    expect((await relay(enqueuer).drain()).claimed).toBe(0);
  });

  it('leaves rows that are not yet available', async () => {
    const later = await insert('email.send', {}, { availableInS: 300 });
    const enqueuer = new MemoryEnqueuer();
    expect((await relay(enqueuer).drain()).claimed).toBe(0);
    expect(await row(later)).toMatchObject({ dispatched: false, attempts: 0 });
  });

  it('backs off unroutable topics with last_error instead of dropping them', async () => {
    const id = await insert('unknown.topic', {});
    const result = await relay(new MemoryEnqueuer()).drain();
    expect(result).toEqual({ claimed: 1, dispatched: 0, failed: 1 });
    const state = await row(id);
    expect(state).toMatchObject({ dispatched: false, attempts: 1 });
    expect(state.last_error).toContain('No queue route');
    expect(state.delay_s).toBeGreaterThan(1);
  });

  it('isolates enqueue failures per row', async () => {
    const ok = await insert('email.send', { n: 1 });
    const broken = await insert('telegram.notify', { n: 2 });
    const enqueuer: JobEnqueuer = {
      add: async (queue: QueueName, _name, _data, jobId) => {
        if (queue === 'telegram') throw new Error('redis unavailable');
        return { jobId, created: true };
      },
    };
    const result = await relay(enqueuer).drain();
    expect(result).toEqual({ claimed: 2, dispatched: 1, failed: 1 });
    expect(await row(ok)).toMatchObject({ dispatched: true });
    expect(await row(broken)).toMatchObject({ dispatched: false, attempts: 1, last_error: 'redis unavailable' });
  });

  it('re-dispatching a row whose commit was lost is deduplicated by job id', async () => {
    const id = await insert('email.send', {});
    const enqueuer = new MemoryEnqueuer();
    await relay(enqueuer).drain();
    await pool.query(`UPDATE ${table} SET dispatched_at = NULL WHERE id = $1`, [id]);
    const result = await relay(enqueuer).drain();
    expect(result.dispatched).toBe(1);
    expect(enqueuer.jobs.filter((job) => job.jobId === id)).toHaveLength(1);
  });

  it('never double-dispatches with concurrent relays (FOR UPDATE SKIP LOCKED)', async () => {
    await pool.query(
      `INSERT INTO ${table} (topic, payload) SELECT 'email.send', jsonb_build_object('n', g) FROM generate_series(1, 250) g`,
    );
    const calls: string[] = [];
    const slow: JobEnqueuer = {
      add: async (_queue, _name, _data, jobId) => {
        calls.push(jobId);
        await sleep(2);
        return { jobId, created: true };
      },
    };
    const [a, b, c] = await Promise.all([relay(slow).drain(), relay(slow).drain(), relay(slow).drain()]);
    expect(a.dispatched + b.dispatched + c.dispatched).toBe(250);
    expect(new Set(calls).size).toBe(250);
    expect(calls).toHaveLength(250);
    const { rows } = await pool.query<{ count: number }>(`SELECT count(*)::int AS count FROM ${table} WHERE dispatched_at IS NULL`);
    expect(rows[0]!.count).toBe(0);
  });

  it('wakes up on LISTEN/NOTIFY without waiting for the poll interval', async () => {
    const enqueuer = new MemoryEnqueuer();
    const instance = relay(enqueuer, { listenConnectionString: databaseUrl!, pollMs: 60_000 });
    await instance.start();
    try {
      await sleep(200);
      const id = await insert('workspace.reembed', { workspace_id: 3 });
      await pool.query(`NOTIFY "${channel}"`);
      const deadline = Date.now() + 3000;
      while (!enqueuer.jobs.some((job) => job.jobId === id) && Date.now() < deadline) await sleep(25);
      expect(enqueuer.jobs.map((job) => job.jobId)).toContain(id);
    } finally {
      await instance.stop();
    }
  });

  it('polls as a fallback when no notification arrives', async () => {
    const enqueuer = new MemoryEnqueuer();
    const instance = relay(enqueuer, { pollMs: 150 });
    await instance.start();
    try {
      const id = await insert('email.send', {});
      const deadline = Date.now() + 3000;
      while (!enqueuer.jobs.some((job) => job.jobId === id) && Date.now() < deadline) await sleep(25);
      expect(enqueuer.jobs.map((job) => job.jobId)).toContain(id);
    } finally {
      await instance.stop();
    }
  });

  it('tolerates a missing outbox table (web not migrated yet)', async () => {
    const missing = new OutboxRelay({
      pool,
      enqueuer: new MemoryEnqueuer(),
      logger: createSilentLogger(),
      table: `${schema}.does_not_exist`,
      channel,
      pollMs: 60_000,
      batchSize: 10,
    });
    await expect(missing.drainOnce()).resolves.toEqual({ claimed: 0, dispatched: 0, failed: 0 });
  });
});
