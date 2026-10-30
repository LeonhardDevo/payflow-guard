import pg from 'pg';
import { withTransaction, isUndefinedTable } from '../db/pool.js';
import { errorFields, type Logger } from '../logger.js';
import type { Metrics } from '../metrics.js';
import type { JobEnqueuer } from '../queues/enqueuer.js';
import { jobOptionsFor, resolveQueue } from '../queues/routes.js';
import { OutboxRepository } from './repository.js';
import type { DrainResult, OutboxJobData, OutboxRow } from './types.js';

export interface OutboxRelayOptions {
  pool: pg.Pool;
  enqueuer: JobEnqueuer;
  logger: Logger;
  metrics?: Metrics;
  table: string;
  channel: string;
  pollMs: number;
  batchSize: number;
  listenConnectionString?: string;
  maxBatchesPerDrain?: number;
}

export class OutboxRelay {
  private readonly repo: OutboxRepository;
  private timer: NodeJS.Timeout | null = null;
  private listener: pg.Client | null = null;
  private reconnectTimer: NodeJS.Timeout | null = null;
  private reconnectDelayMs = 1000;
  private running: Promise<void> | null = null;
  private pending = false;
  private stopped = true;
  private missingTableWarned = false;

  constructor(private readonly options: OutboxRelayOptions) {
    if (!/^[a-z_][a-z0-9_]*$/.test(options.channel)) throw new Error(`Unsafe NOTIFY channel: ${options.channel}`);
    this.repo = new OutboxRepository(options.table);
  }

  async start(): Promise<void> {
    if (!this.stopped) return;
    this.stopped = false;
    this.timer = setInterval(() => this.trigger(), this.options.pollMs);
    this.timer.unref();
    if (this.options.listenConnectionString) await this.connectListener();
    this.trigger();
    this.options.logger.info(
      { table: this.options.table, channel: this.options.channel, pollMs: this.options.pollMs },
      'outbox relay started',
    );
  }

  async stop(): Promise<void> {
    this.stopped = true;
    if (this.timer) clearInterval(this.timer);
    if (this.reconnectTimer) clearTimeout(this.reconnectTimer);
    this.timer = null;
    this.reconnectTimer = null;
    const listener = this.listener;
    this.listener = null;
    if (listener) await listener.end().catch(() => undefined);
    if (this.running) await this.running.catch(() => undefined);
  }

  trigger(): void {
    if (this.stopped) return;
    if (this.running) {
      this.pending = true;
      return;
    }
    this.running = this.drain()
      .then(() => undefined)
      .catch((error: unknown) => {
        this.options.metrics?.outboxErrors.inc({ reason: 'drain' });
        this.options.logger.error(errorFields(error), 'outbox drain failed');
      })
      .finally(() => {
        this.running = null;
        if (this.pending) {
          this.pending = false;
          this.trigger();
        }
      });
  }

  async drain(): Promise<DrainResult> {
    const total: DrainResult = { claimed: 0, dispatched: 0, failed: 0 };
    const maxBatches = this.options.maxBatchesPerDrain ?? 50;
    for (let i = 0; i < maxBatches; i++) {
      const result = await this.drainOnce();
      total.claimed += result.claimed;
      total.dispatched += result.dispatched;
      total.failed += result.failed;
      if (result.claimed < this.options.batchSize) break;
    }
    return total;
  }

  async drainOnce(): Promise<DrainResult> {
    try {
      const result = await withTransaction(this.options.pool, async (client) => {
        const rows = await this.repo.claim(client, this.options.batchSize);
        if (rows.length === 0) return { claimed: 0, dispatched: 0, failed: 0 };
        const outcomes = await Promise.all(rows.map((row) => this.dispatch(row)));
        const dispatched = outcomes.filter((outcome) => outcome.ok).map((outcome) => outcome.id);
        await this.repo.markDispatched(client, dispatched);
        let failed = 0;
        for (const outcome of outcomes) {
          if (outcome.ok) continue;
          failed++;
          await this.repo.markFailed(client, outcome.id, outcome.error);
        }
        return { claimed: rows.length, dispatched: dispatched.length, failed };
      });
      this.missingTableWarned = false;
      if (result.claimed > 0) this.options.logger.debug(result, 'outbox batch processed');
      return result;
    } catch (error) {
      if (isUndefinedTable(error)) {
        if (!this.missingTableWarned) {
          this.options.logger.warn({ table: this.options.table }, 'outbox table does not exist yet; waiting for web migrations');
          this.missingTableWarned = true;
        }
        return { claimed: 0, dispatched: 0, failed: 0 };
      }
      throw error;
    }
  }

  private async dispatch(row: OutboxRow): Promise<{ ok: true; id: string } | { ok: false; id: string; error: string }> {
    const log = this.options.logger.child({ outboxId: row.id, topic: row.topic });
    const queue = resolveQueue(row.topic);
    if (!queue) {
      this.options.metrics?.outboxErrors.inc({ reason: 'unroutable' });
      log.error({ attempts: row.attempts + 1 }, 'outbox topic has no queue route');
      return { ok: false, id: row.id, error: `No queue route for topic "${row.topic}"` };
    }
    const data: OutboxJobData = {
      outbox_id: row.id,
      topic: row.topic,
      workspace_id: row.workspace_id,
      payload: row.payload,
    };
    try {
      const job = jobOptionsFor(row.topic, row.payload);
      const { created } = await this.options.enqueuer.add(queue, row.topic, data, row.id, job ? { job } : {});
      this.options.metrics?.outboxDispatched.inc({ queue });
      log.info({ queue, created }, 'outbox message dispatched');
      return { ok: true, id: row.id };
    } catch (error) {
      this.options.metrics?.outboxErrors.inc({ reason: 'enqueue' });
      log.warn({ queue, ...errorFields(error) }, 'outbox enqueue failed; will retry');
      return { ok: false, id: row.id, error: error instanceof Error ? error.message : String(error) };
    }
  }

  private async connectListener(): Promise<void> {
    if (this.stopped || !this.options.listenConnectionString) return;
    const client = new pg.Client({ connectionString: this.options.listenConnectionString, application_name: 'worker-outbox-listener' });
    client.on('notification', (message) => {
      if (message.channel === this.options.channel) this.trigger();
    });
    client.on('error', (error) => {
      this.options.logger.warn(errorFields(error), 'outbox LISTEN connection error');
      this.scheduleReconnect(client);
    });
    client.on('end', () => this.scheduleReconnect(client));
    try {
      await client.connect();
      await client.query(`LISTEN "${this.options.channel}"`);
      this.listener = client;
      this.reconnectDelayMs = 1000;
      this.options.logger.debug({ channel: this.options.channel }, 'outbox LISTEN connected');
    } catch (error) {
      this.options.logger.warn(errorFields(error), 'outbox LISTEN connect failed; relying on polling');
      this.scheduleReconnect(client);
    }
  }

  private scheduleReconnect(client: pg.Client): void {
    if (this.stopped || this.reconnectTimer) return;
    if (this.listener === client) this.listener = null;
    client.removeAllListeners('notification');
    client.end().catch(() => undefined);
    const delay = this.reconnectDelayMs;
    this.reconnectDelayMs = Math.min(this.reconnectDelayMs * 2, 30_000);
    this.reconnectTimer = setTimeout(() => {
      this.reconnectTimer = null;
      void this.connectListener();
    }, delay);
    this.reconnectTimer.unref();
  }
}
