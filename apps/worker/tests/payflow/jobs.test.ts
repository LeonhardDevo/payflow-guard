import { UnrecoverableError } from 'bullmq';
import { Registry } from 'prom-client';
import { beforeEach, describe, expect, it } from 'vitest';
import { IMPORT_TOPIC, jobIds, localDate, PF_JOBS, PF_SCHEDULERS, tickEpoch } from '../../src/domain/payflow/jobs/definitions.js';
import { PayflowJobs, simulatedRunTime } from '../../src/domain/payflow/jobs/handlers.js';
import { registerPayflowSchedulers } from '../../src/domain/payflow/jobs/runner.js';
import { PayflowMetrics } from '../../src/domain/payflow/metrics.js';
import { createSilentLogger } from '../../src/logger.js';
import { MemoryEnqueuer } from '../../src/queues/enqueuer.js';
import { QUEUES } from '../../src/queues/names.js';
import { fakePayflowApi, jobContext } from './fakes.js';

const NOW = Date.parse('2026-10-23T09:07:30Z');

let api: ReturnType<typeof fakePayflowApi>;
let enqueuer: MemoryEnqueuer;
let registry: Registry;
let jobs: PayflowJobs;
let handlers: ReturnType<PayflowJobs['handlers']>;

beforeEach(() => {
  api = fakePayflowApi();
  enqueuer = new MemoryEnqueuer();
  registry = new Registry();
  jobs = new PayflowJobs({ api, enqueuer, metrics: new PayflowMetrics(registry), clock: () => NOW });
  handlers = jobs.handlers();
});

const run = (queue: keyof typeof handlersOf, name: string, data: unknown, ctx = jobContext()) => handlers[handlersOf[queue]]![name]!(data, ctx);
const handlersOf = {
  scheduler: QUEUES.pfScheduler,
  reminders: QUEUES.pfReminders,
  analytics: QUEUES.pfAnalytics,
  payments: QUEUES.pfPayments,
  imports: QUEUES.pfImports,
} as const;

async function metric(name: string): Promise<Array<{ labels: Record<string, string | number>; value: number }>> {
  const found = (await registry.getMetricsAsJSON()).find((entry) => entry.name === name);
  return (found?.values ?? []) as Array<{ labels: Record<string, string | number>; value: number }>;
}

describe('PayFlow scheduler fan-out', () => {
  it('registers the six job schedulers in UTC with hyphenated ids', async () => {
    const calls: Array<{ id: string; pattern: string; tz: string; name: string }> = [];
    await registerPayflowSchedulers(
      {
        upsertJobScheduler: (async (id: string, repeat: { pattern: string; tz: string }, template: { name: string }) => {
          calls.push({ id, pattern: repeat.pattern, tz: repeat.tz, name: template.name });
          return undefined as never;
        }) as never,
        removeJobScheduler: async () => true,
      },
      createSilentLogger(),
    );
    expect(calls).toEqual([
      { id: 'pf-reminders-plan', pattern: '*/15 * * * *', tz: 'UTC', name: 'reminders:plan-all' },
      { id: 'pf-risk-recompute', pattern: '15 2 * * *', tz: 'UTC', name: 'risk:recompute-all' },
      { id: 'pf-digests', pattern: '0 * * * *', tz: 'UTC', name: 'digests:run' },
      { id: 'pf-payments-sync', pattern: '*/30 * * * *', tz: 'UTC', name: 'payments:sync-all' },
      { id: 'pf-guard-hourly', pattern: '5 * * * *', tz: 'UTC', name: 'guard:hourly' },
      { id: 'pf-maintenance-purge', pattern: '30 3 * * *', tz: 'UTC', name: 'maintenance:purge' },
    ]);
    expect(PF_SCHEDULERS.every((scheduler) => !scheduler.id.includes(':'))).toBe(true);
  });

  it('plans every active workspace once per 15-minute tick', async () => {
    const result = await run('scheduler', PF_JOBS.remindersPlanAll, { scheduled: true });
    const tick = tickEpoch(NOW, 15 * 60_000);
    expect(tick).toBe(Date.parse('2026-10-23T09:00:00Z') / 1000);
    expect(result).toEqual({ enqueued: 2, workspaces: 2 });
    expect(enqueuer.jobs.map((job) => [job.queue, job.name, job.jobId])).toEqual([
      ['pf-reminders', 'reminders:plan', `plan-1-${tick}`],
      ['pf-reminders', 'reminders:plan', `plan-2-${tick}`],
    ]);
    expect(await run('scheduler', PF_JOBS.remindersPlanAll, {})).toEqual({ enqueued: 0, workspaces: 2 });
  });

  it('forwards the dev clock and uses it for tick ids', async () => {
    const now = '2026-11-02T12:40:00.000Z';
    await run('scheduler', PF_JOBS.remindersPlanAll, { now });
    expect(enqueuer.jobs[0]).toMatchObject({ jobId: `plan-1-${Date.parse('2026-11-02T12:30:00Z') / 1000}`, data: { workspace_id: 1, now } });
  });

  it('recomputes risk once per workspace and local day', async () => {
    await run('scheduler', PF_JOBS.riskRecomputeAll, {});
    expect(enqueuer.jobs.map((job) => job.jobId)).toEqual(['risk-1-20261023', 'risk-2-20261023']);
    expect(localDate(Date.parse('2026-10-23T02:15:00Z'), 'America/New_York')).toBe('2026-10-22');
    expect(localDate(Date.parse('2026-10-23T02:15:00Z'), 'Not/AZone')).toBe('2026-10-23');
    await run('analytics', PF_JOBS.riskRecompute, { workspace_id: 1, now: null });
    expect(api.recomputeRisk).toHaveBeenCalledWith(1, null, 'req-1');
  });

  it('syncs open payment links every 30 minutes and counts recorded payments', async () => {
    await run('scheduler', PF_JOBS.paymentsSyncAll, {});
    const tick = Date.parse('2026-10-23T09:00:00Z') / 1000;
    expect(api.syncCandidates).toHaveBeenCalledWith(100, 'req-1');
    expect(enqueuer.jobs.map((job) => job.jobId)).toEqual([`sync-11-${tick}`, `sync-12-${tick}`]);
    expect(enqueuer.jobs[0]?.options).toMatchObject({ attempts: 5, backoff: { type: 'exponential', delay: 10_000 } });
    await run('payments', PF_JOBS.paymentsSync, { payment_link_id: 11, workspace_id: 1 });
    expect(api.syncPaymentLink).toHaveBeenCalledWith(11, 'req-1');
    expect((await metric('pf_payment_sync_recorded_total'))[0]?.value).toBe(1);
  });

  it('runs digests, guard escalations and the retention purge with the forwarded clock', async () => {
    const now = '2026-10-24T07:00:00.000Z';
    expect(await run('scheduler', PF_JOBS.digestsRun, { now })).toMatchObject({ enqueued: 4, workspaces: 1, messages: 4 });
    expect(api.runDigests).toHaveBeenCalledWith(now, 'req-1');
    expect(await run('scheduler', PF_JOBS.guardHourly, { now })).toMatchObject({ enqueued: 4, escalated: 1 });
    expect(api.runEscalations).toHaveBeenCalledWith(now, 'req-1');
    expect(await run('scheduler', PF_JOBS.maintenancePurge, {})).toMatchObject({ enqueued: 5 });
    expect(api.purge).toHaveBeenCalledWith(null, 'req-1');
  });

  it('rejects malformed job data permanently', async () => {
    await expect(run('reminders', PF_JOBS.remindersPlan, { workspace_id: 'x' })).rejects.toBeInstanceOf(UnrecoverableError);
    await expect(run('scheduler', PF_JOBS.digestsRun, { now: 'yesterday' })).rejects.toBeInstanceOf(UnrecoverableError);
  });
});

describe('reminder planning and dispatch', () => {
  it('enqueues a delayed dispatch per planned reminder and dedupes the next tick', async () => {
    api.planReminders.mockResolvedValue({
      reminders: [
        { id: 41, scheduled_for: '2026-10-23T09:17:00Z' },
        { id: 42, scheduled_for: '2026-10-23T08:00:00Z' },
      ],
      created: 2,
      superseded: 1,
    });
    const data = { workspace_id: 1, tick: 1, now: null };
    expect(await run('reminders', PF_JOBS.remindersPlan, data)).toEqual({ enqueued: 2, reminders: 2, created: 2, superseded: 1 });
    expect(api.planReminders).toHaveBeenCalledWith(1, null, 'req-1');
    expect(enqueuer.jobs.map((job) => [job.jobId, job.options?.delay])).toEqual([
      [`rem-41-${Date.parse('2026-10-23T09:17:00Z') / 1000}`, Date.parse('2026-10-23T09:17:00Z') - NOW],
      [`rem-42-${Date.parse('2026-10-23T08:00:00Z') / 1000}`, 0],
    ]);
    expect(enqueuer.jobs[0]?.options).toMatchObject({ attempts: 8, backoff: { type: 'exponential', delay: 30_000 }, removeOnComplete: true, removeOnFail: true });
    expect(await run('reminders', PF_JOBS.remindersPlan, data)).toMatchObject({ enqueued: 0 });
  });

  it('records queued dispatches with their lag', async () => {
    const result = await run('reminders', PF_JOBS.remindersDispatch, { reminder_id: 41, scheduled_for: '2026-10-23T09:07:00Z' });
    expect(result).toEqual({ status: 'queued' });
    expect(api.dispatchReminder).toHaveBeenCalledWith(41, '2026-10-23T09:07:00Z', null, 'req-1');
    expect(await metric('pf_reminders_dispatched_total')).toEqual([expect.objectContaining({ labels: { status: 'queued' }, value: 1 })]);
    const lag = (await metric('pf_reminder_dispatch_lag_seconds')).find((entry) => (entry as { metricName?: string }).metricName === 'pf_reminder_dispatch_lag_seconds_sum');
    expect(lag?.value).toBe(30);
  });

  it('re-enqueues a rescheduled reminder at its new slot', async () => {
    api.dispatchReminder.mockResolvedValueOnce({ status: 'rescheduled', scheduled_for: '2026-10-26T09:12:00Z' });
    const result = await run('reminders', PF_JOBS.remindersDispatch, { reminder_id: 41, scheduled_for: '2026-10-23T09:00:00Z' });
    const epoch = Date.parse('2026-10-26T09:12:00Z') / 1000;
    expect(result).toMatchObject({ status: 'rescheduled', followUp: `rem-41-${epoch}` });
    expect(enqueuer.jobs[0]).toMatchObject({
      jobId: `rem-41-${epoch}`,
      data: { reminder_id: 41, scheduled_for: '2026-10-26T09:12:00Z', retry: 0 },
      options: { delay: Date.parse('2026-10-26T09:12:00Z') - NOW },
    });
  });

  it('re-adds a too-early reminder with a numbered suffix and gives up after five', async () => {
    api.dispatchReminder.mockResolvedValue({ status: 'too_early', scheduled_for: '2026-10-23T09:20:00Z' });
    const epoch = Date.parse('2026-10-23T09:20:00Z') / 1000;
    await run('reminders', PF_JOBS.remindersDispatch, { reminder_id: 41, scheduled_for: '2026-10-23T09:20:00Z' });
    await run('reminders', PF_JOBS.remindersDispatch, { reminder_id: 41, scheduled_for: '2026-10-23T09:20:00Z', retry: 4 });
    await run('reminders', PF_JOBS.remindersDispatch, { reminder_id: 41, scheduled_for: '2026-10-23T09:20:00Z', retry: 5 });
    expect(enqueuer.jobs.map((job) => job.jobId)).toEqual([`rem-41-${epoch}-r1`, `rem-41-${epoch}-r5`]);
    expect(jobIds.dispatchRetry(41, '2026-10-23T09:20:00Z', 3)).toBe(`rem-41-${epoch}-r3`);
  });

  it('finishes skipped and already processed reminders without follow-up jobs', async () => {
    api.dispatchReminder.mockResolvedValueOnce({ status: 'skipped', reason: 'invoice_paid' });
    api.dispatchReminder.mockResolvedValueOnce({ status: 'already_processed', current: 'sent' });
    await run('reminders', PF_JOBS.remindersDispatch, { reminder_id: 41, scheduled_for: '2026-10-23T09:00:00Z' });
    await run('reminders', PF_JOBS.remindersDispatch, { reminder_id: 41, scheduled_for: '2026-10-23T09:00:00Z' });
    expect(enqueuer.jobs).toHaveLength(0);
  });

  it('runs a promoted dev-clock dispatch at its scheduled time', async () => {
    const now = '2026-10-23T12:00:00.000Z';
    expect(simulatedRunTime(now, '2026-10-24T06:17:00Z')).toBe('2026-10-24T06:17:00.000Z');
    expect(simulatedRunTime(now, '2026-10-23T08:00:00Z')).toBe(now);
    expect(simulatedRunTime(null, '2026-10-24T06:17:00Z')).toBeNull();
    await run('reminders', PF_JOBS.remindersDispatch, { reminder_id: 41, scheduled_for: '2026-10-24T06:17:00Z', now });
    expect(api.dispatchReminder).toHaveBeenCalledWith(41, '2026-10-24T06:17:00Z', '2026-10-24T06:17:00.000Z', 'req-1');
  });
});

describe('CSV import processing', () => {
  const envelope = { outbox_id: '7d7f1a52-0d1f-4d9e-9ad4-4f3e6c2c1b10', topic: IMPORT_TOPIC, workspace_id: 1, payload: { import_id: 9, workspace_id: 1 } };

  it('loops 200-row batches until done and reports progress', async () => {
    const progress: unknown[] = [];
    const result = await run('imports', IMPORT_TOPIC, envelope, jobContext({ reportProgress: async (value) => void progress.push(value) }));
    expect(api.processImportBatch.mock.calls.map((call) => [call[0], call[1], call[2]])).toEqual([
      [9, 0, 200],
      [9, 200, 200],
    ]);
    expect(result).toEqual({ batches: 2, processed: 400, imported: 380, skipped: 10, failed: 10 });
    expect(progress.at(-1)).toMatchObject({ offset: 400, done: true });
  });

  it('resumes from the saved offset after a retry', async () => {
    await run('imports', PF_JOBS.importsProcess, { import_id: 9, workspace_id: 1 }, jobContext({ progress: { offset: 200, done: false } }));
    expect(api.processImportBatch).toHaveBeenCalledTimes(1);
    expect(api.processImportBatch).toHaveBeenCalledWith(9, 200, 200, 'req-1');
  });

  it('stops permanently when web does not advance the offset', async () => {
    api.processImportBatch.mockResolvedValue({ processed: 0, imported: 0, skipped: 0, failed: 0, done: false, next_offset: 0 });
    await expect(run('imports', IMPORT_TOPIC, envelope)).rejects.toBeInstanceOf(UnrecoverableError);
  });
});
