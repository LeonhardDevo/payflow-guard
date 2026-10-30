import { Counter, Gauge, Histogram, Registry, collectDefaultMetrics } from 'prom-client';

export type QueueDepthSource = () => Promise<Array<{ queue: string; counts: Record<string, number> }>>;

export class Metrics {
  readonly registry = new Registry();
  readonly jobsTotal: Counter<'queue' | 'name' | 'status'>;
  readonly jobDuration: Histogram<'queue' | 'name' | 'status'>;
  readonly queueDepth: Gauge<'queue' | 'state'>;
  readonly outboxDispatched: Counter<'queue'>;
  readonly outboxErrors: Counter<'reason'>;
  readonly webhooks: Counter<'provider' | 'result'>;
  readonly botUpdates: Counter<'kind'>;
  private depthSource: QueueDepthSource | null = null;

  constructor(options: { defaultMetrics?: boolean; prefix?: string } = {}) {
    const prefix = options.prefix ?? 'worker_';
    if (options.defaultMetrics ?? true) {
      collectDefaultMetrics({ register: this.registry, prefix });
    }
    this.jobsTotal = new Counter({
      name: `${prefix}jobs_total`,
      help: 'Processed jobs by queue, job name and final status of the attempt.',
      labelNames: ['queue', 'name', 'status'],
      registers: [this.registry],
    });
    this.jobDuration = new Histogram({
      name: `${prefix}job_duration_seconds`,
      help: 'Job attempt duration in seconds.',
      labelNames: ['queue', 'name', 'status'],
      buckets: [0.01, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30, 60, 180, 600],
      registers: [this.registry],
    });
    const getDepthSource = (): QueueDepthSource | null => this.depthSource;
    this.queueDepth = new Gauge({
      name: `${prefix}queue_depth`,
      help: 'Jobs per queue and state, sampled at scrape time.',
      labelNames: ['queue', 'state'],
      registers: [this.registry],
      async collect() {
        const source = getDepthSource();
        if (!source) return;
        try {
          const samples = await source();
          this.reset();
          for (const sample of samples) {
            for (const [state, value] of Object.entries(sample.counts)) {
              this.set({ queue: sample.queue, state }, value);
            }
          }
        } catch {
          return;
        }
      },
    });
    this.outboxDispatched = new Counter({
      name: `${prefix}outbox_dispatched_total`,
      help: 'Outbox messages enqueued to BullMQ.',
      labelNames: ['queue'],
      registers: [this.registry],
    });
    this.outboxErrors = new Counter({
      name: `${prefix}outbox_errors_total`,
      help: 'Outbox dispatch errors.',
      labelNames: ['reason'],
      registers: [this.registry],
    });
    this.webhooks = new Counter({
      name: `${prefix}webhooks_total`,
      help: 'Inbound webhooks by provider and result.',
      labelNames: ['provider', 'result'],
      registers: [this.registry],
    });
    this.botUpdates = new Counter({
      name: `${prefix}bot_updates_total`,
      help: 'Telegram updates handled by the bot, by kind.',
      labelNames: ['kind'],
      registers: [this.registry],
    });
  }

  setQueueDepthSource(source: QueueDepthSource): void {
    this.depthSource = source;
  }

  async render(): Promise<{ contentType: string; body: string }> {
    return { contentType: this.registry.contentType, body: await this.registry.metrics() };
  }
}
