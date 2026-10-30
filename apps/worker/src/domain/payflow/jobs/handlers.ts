import { z } from 'zod';
import { parseOutboxJob, permanent, type HandlerMap, type JobContext, type JobHandler } from '../../../jobs/types.js';
import type { JobEnqueuer } from '../../../queues/enqueuer.js';
import { QUEUES, type QueueName } from '../../../queues/names.js';
import type { DispatchResult, PayflowApi } from '../api.js';
import type { PayflowMetrics } from '../metrics.js';
import {
  dispatchJobOptions,
  IMPORT_BATCH_SIZE,
  IMPORT_TOPIC,
  jobIds,
  localDate,
  MAX_TOO_EARLY_RETRIES,
  PF_JOBS,
  PLAN_JOB_OPTIONS,
  PLAN_TICK_MS,
  RISK_JOB_OPTIONS,
  SYNC_CANDIDATE_LIMIT,
  SYNC_JOB_OPTIONS,
  SYNC_TICK_MS,
  tickEpoch,
  type SchedulerJob,
} from './definitions.js';

export interface PayflowJobDeps {
  api: PayflowApi;
  enqueuer: JobEnqueuer;
  metrics?: PayflowMetrics | null;
  clock?: () => number;
}

export interface SchedulerRunResult {
  enqueued: number;
  [key: string]: unknown;
}

const nowSchema = z
  .string()
  .refine((value) => Number.isFinite(Date.parse(value)), 'must be an RFC 3339 instant')
  .nullish()
  .transform((value) => value ?? null);

const schedulerDataSchema = z.object({ now: nowSchema }).passthrough();
const planDataSchema = z.object({ workspace_id: z.number().int().positive(), tick: z.number().int().nonnegative(), now: nowSchema });
const dispatchDataSchema = z.object({
  reminder_id: z.number().int().positive(),
  scheduled_for: z.string().refine((value) => Number.isFinite(Date.parse(value))),
  retry: z.number().int().min(0).max(MAX_TOO_EARLY_RETRIES).default(0),
  now: nowSchema,
});
const riskDataSchema = z.object({ workspace_id: z.number().int().positive(), now: nowSchema });
const syncDataSchema = z.object({ payment_link_id: z.number().int().positive(), workspace_id: z.number().int().positive().nullish(), now: nowSchema });
const importPayloadSchema = z.object({ import_id: z.number().int().positive(), workspace_id: z.number().int().positive() });
const importDirectSchema = importPayloadSchema.extend({ outbox_id: z.string().optional() });
const progressSchema = z.object({ offset: z.number().int().nonnegative() }).passthrough();

const MAX_IMPORT_BATCHES = 10_000;

function parse<S extends z.ZodType>(schema: S, data: unknown, job: string): z.output<S> {
  const parsed = schema.safeParse(data ?? {});
  if (!parsed.success) throw permanent(`Invalid data for ${job}: ${z.prettifyError(parsed.error)}`);
  return parsed.data;
}

export class PayflowJobs {
  private readonly clock: () => number;

  constructor(private readonly deps: PayflowJobDeps) {
    this.clock = deps.clock ?? Date.now;
  }

  handlers(): Partial<Record<QueueName, HandlerMap>> {
    const scheduler = (job: SchedulerJob): JobHandler => async (data, ctx) => this.runScheduler(job, parse(schedulerDataSchema, data, job).now, ctx);
    const importHandler: JobHandler = async (data, ctx) => this.processImport(data, ctx);
    return {
      [QUEUES.pfScheduler]: {
        [PF_JOBS.remindersPlanAll]: scheduler(PF_JOBS.remindersPlanAll),
        [PF_JOBS.riskRecomputeAll]: scheduler(PF_JOBS.riskRecomputeAll),
        [PF_JOBS.digestsRun]: scheduler(PF_JOBS.digestsRun),
        [PF_JOBS.paymentsSyncAll]: scheduler(PF_JOBS.paymentsSyncAll),
        [PF_JOBS.guardHourly]: scheduler(PF_JOBS.guardHourly),
        [PF_JOBS.maintenancePurge]: scheduler(PF_JOBS.maintenancePurge),
      },
      [QUEUES.pfReminders]: {
        [PF_JOBS.remindersPlan]: async (data, ctx) => this.plan(parse(planDataSchema, data, PF_JOBS.remindersPlan), ctx),
        [PF_JOBS.remindersDispatch]: async (data, ctx) => this.dispatch(parse(dispatchDataSchema, data, PF_JOBS.remindersDispatch), ctx),
      },
      [QUEUES.pfAnalytics]: {
        [PF_JOBS.riskRecompute]: async (data, ctx) => {
          const input = parse(riskDataSchema, data, PF_JOBS.riskRecompute);
          const result = await this.deps.api.recomputeRisk(input.workspace_id, input.now, ctx.requestId);
          ctx.logger.info({ workspaceId: input.workspace_id, ...result }, 'risk recomputed');
          return result;
        },
      },
      [QUEUES.pfPayments]: {
        [PF_JOBS.paymentsSync]: async (data, ctx) => {
          const input = parse(syncDataSchema, data, PF_JOBS.paymentsSync);
          const result = await this.deps.api.syncPaymentLink(input.payment_link_id, ctx.requestId);
          if (result.recorded > 0) this.deps.metrics?.paymentSyncRecorded.inc(result.recorded);
          ctx.logger.info({ paymentLinkId: input.payment_link_id, ...result }, 'payment link synced');
          return result;
        },
      },
      [QUEUES.pfImports]: {
        [IMPORT_TOPIC]: importHandler,
        [PF_JOBS.importsProcess]: importHandler,
      },
    };
  }

  async runScheduler(job: SchedulerJob, now: string | null, ctx: Pick<JobContext, 'requestId' | 'logger'>): Promise<SchedulerRunResult> {
    switch (job) {
      case PF_JOBS.remindersPlanAll:
        return this.planAll(now, ctx);
      case PF_JOBS.riskRecomputeAll:
        return this.riskAll(now, ctx);
      case PF_JOBS.paymentsSyncAll:
        return this.syncAll(now, ctx);
      case PF_JOBS.digestsRun: {
        const result = await this.deps.api.runDigests(now, ctx.requestId);
        ctx.logger.info(result, 'digests run');
        return { enqueued: result.messages, ...result };
      }
      case PF_JOBS.guardHourly: {
        const result = await this.deps.api.runEscalations(now, ctx.requestId);
        ctx.logger.info(result, 'guard escalations run');
        const total = result.escalated + result.confirmations_reminded + result.confirmations_expired + result.receiving_details_activated;
        return { enqueued: total, ...result };
      }
      case PF_JOBS.maintenancePurge: {
        const result = await this.deps.api.purge(now, ctx.requestId);
        ctx.logger.info(result, 'maintenance purge run');
        return { enqueued: Object.values(result.purged).reduce((sum, value) => sum + value, 0), ...result };
      }
    }
  }

  private nowMs(now: string | null): number {
    return now === null ? this.clock() : Date.parse(now);
  }

  private async planAll(now: string | null, ctx: Pick<JobContext, 'requestId' | 'logger'>): Promise<SchedulerRunResult> {
    const workspaces = await this.deps.api.activeWorkspaces(ctx.requestId);
    const tick = tickEpoch(this.nowMs(now), PLAN_TICK_MS);
    let enqueued = 0;
    for (const workspace of workspaces) {
      const { created } = await this.deps.enqueuer.add(
        QUEUES.pfReminders,
        PF_JOBS.remindersPlan,
        { workspace_id: workspace.id, tick, now },
        jobIds.plan(workspace.id, tick),
        { job: PLAN_JOB_OPTIONS },
      );
      if (created) enqueued++;
    }
    ctx.logger.info({ workspaces: workspaces.length, enqueued, tick }, 'reminder planning fanned out');
    return { enqueued, workspaces: workspaces.length };
  }

  private async riskAll(now: string | null, ctx: Pick<JobContext, 'requestId' | 'logger'>): Promise<SchedulerRunResult> {
    const workspaces = await this.deps.api.activeWorkspaces(ctx.requestId);
    const nowMs = this.nowMs(now);
    let enqueued = 0;
    for (const workspace of workspaces) {
      const { created } = await this.deps.enqueuer.add(
        QUEUES.pfAnalytics,
        PF_JOBS.riskRecompute,
        { workspace_id: workspace.id, now },
        jobIds.risk(workspace.id, localDate(nowMs, workspace.timezone)),
        { job: RISK_JOB_OPTIONS },
      );
      if (created) enqueued++;
    }
    ctx.logger.info({ workspaces: workspaces.length, enqueued }, 'risk recompute fanned out');
    return { enqueued, workspaces: workspaces.length };
  }

  private async syncAll(now: string | null, ctx: Pick<JobContext, 'requestId' | 'logger'>): Promise<SchedulerRunResult> {
    const candidates = await this.deps.api.syncCandidates(SYNC_CANDIDATE_LIMIT, ctx.requestId);
    const tick = tickEpoch(this.nowMs(now), SYNC_TICK_MS);
    let enqueued = 0;
    for (const candidate of candidates) {
      const { created } = await this.deps.enqueuer.add(
        QUEUES.pfPayments,
        PF_JOBS.paymentsSync,
        { payment_link_id: candidate.id, workspace_id: candidate.workspace_id, now },
        jobIds.sync(candidate.id, tick),
        { job: SYNC_JOB_OPTIONS },
      );
      if (created) enqueued++;
    }
    ctx.logger.info({ candidates: candidates.length, enqueued, tick }, 'payment link sync fanned out');
    return { enqueued, candidates: candidates.length };
  }

  private async plan(input: z.output<typeof planDataSchema>, ctx: JobContext): Promise<SchedulerRunResult> {
    const result = await this.deps.api.planReminders(input.workspace_id, input.now, ctx.requestId);
    let enqueued = 0;
    for (const reminder of result.reminders) {
      if (await this.enqueueDispatch(reminder.id, reminder.scheduled_for, input.now, 0)) enqueued++;
    }
    ctx.logger.info(
      { workspaceId: input.workspace_id, reminders: result.reminders.length, created: result.created, superseded: result.superseded, enqueued },
      'reminders planned',
    );
    return { enqueued, reminders: result.reminders.length, created: result.created, superseded: result.superseded };
  }

  private async dispatch(input: z.output<typeof dispatchDataSchema>, ctx: JobContext): Promise<DispatchResult & { followUp?: string }> {
    const now = simulatedRunTime(input.now, input.scheduled_for);
    const result = await this.deps.api.dispatchReminder(input.reminder_id, input.scheduled_for, now, ctx.requestId);
    this.deps.metrics?.remindersDispatched.inc({ status: result.status });
    const log = ctx.logger.child({ reminderId: input.reminder_id, status: result.status });
    if (result.status === 'queued') {
      const lag = (this.nowMs(now) - Date.parse(input.scheduled_for)) / 1000;
      this.deps.metrics?.dispatchLag.observe(Math.max(0, lag));
      log.info({ reason: result.reason ?? null }, 'reminder dispatched');
      return result;
    }
    if (result.status === 'rescheduled' && result.scheduled_for) {
      await this.enqueueDispatch(input.reminder_id, result.scheduled_for, now, 0);
      log.info({ scheduledFor: result.scheduled_for }, 'reminder rescheduled');
      return { ...result, followUp: jobIds.dispatch(input.reminder_id, result.scheduled_for) };
    }
    if (result.status === 'too_early') {
      const scheduledFor = result.scheduled_for ?? input.scheduled_for;
      const attempt = input.retry + 1;
      if (attempt > MAX_TOO_EARLY_RETRIES) {
        log.warn({ scheduledFor, attempt }, 'reminder still too early after retries; the next planning tick re-enqueues it');
        return result;
      }
      await this.enqueueDispatch(input.reminder_id, scheduledFor, now, attempt);
      log.info({ scheduledFor, attempt }, 'reminder too early; re-enqueued');
      return { ...result, followUp: jobIds.dispatchRetry(input.reminder_id, scheduledFor, attempt) };
    }
    log.info({ reason: result.reason ?? null, current: result.current ?? null }, 'reminder dispatch finished');
    return result;
  }

  private async enqueueDispatch(reminderId: number, scheduledFor: string, now: string | null, retry: number): Promise<boolean> {
    const delay = Date.parse(scheduledFor) - this.nowMs(now);
    const jobId = retry === 0 ? jobIds.dispatch(reminderId, scheduledFor) : jobIds.dispatchRetry(reminderId, scheduledFor, retry);
    const data = { reminder_id: reminderId, scheduled_for: scheduledFor, retry, now };
    const { created } = await this.deps.enqueuer.add(QUEUES.pfReminders, PF_JOBS.remindersDispatch, data, jobId, {
      job: dispatchJobOptions(delay),
    });
    return created;
  }

  private async processImport(data: unknown, ctx: JobContext): Promise<{ batches: number; processed: number; imported: number; skipped: number; failed: number }> {
    const direct = importDirectSchema.safeParse(data);
    const payload = direct.success ? direct.data : parseOutboxJob(data, importPayloadSchema).payload;
    const resumed = progressSchema.safeParse(ctx.progress);
    let offset = resumed.success ? resumed.data.offset : 0;
    const totals = { batches: 0, processed: 0, imported: 0, skipped: 0, failed: 0 };
    for (let batch = 0; batch < MAX_IMPORT_BATCHES; batch++) {
      const result = await this.deps.api.processImportBatch(payload.import_id, offset, IMPORT_BATCH_SIZE, ctx.requestId);
      totals.batches++;
      totals.processed += result.processed;
      totals.imported += result.imported;
      totals.skipped += result.skipped;
      totals.failed += result.failed;
      if (result.done) {
        await ctx.reportProgress?.({ offset: result.next_offset, done: true, ...totals });
        ctx.logger.info({ importId: payload.import_id, ...totals }, 'import processed');
        return totals;
      }
      if (result.next_offset <= offset) throw permanent(`import ${payload.import_id} did not advance past offset ${offset}`);
      offset = result.next_offset;
      await ctx.reportProgress?.({ offset, done: false, ...totals });
    }
    throw permanent(`import ${payload.import_id} exceeded ${MAX_IMPORT_BATCHES} batches`);
  }
}

export function simulatedRunTime(now: string | null, scheduledFor: string): string | null {
  if (now === null) return null;
  return Date.parse(scheduledFor) > Date.parse(now) ? new Date(Date.parse(scheduledFor)).toISOString() : now;
}
