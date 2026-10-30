import { z } from 'zod';
import { UpstreamHttpError } from '../../clients/errors.js';
import { internalFetch, type HttpMethod, type InternalTarget, type QueryValue } from '../../clients/internalFetch.js';

export const PAYFLOW_INTERNAL_PREFIX = '/internal/v1/payflow';

const id = z.number().int().positive();
const count = z.number().int().nonnegative().default(0);
const instant = z.string().refine((value) => Number.isFinite(Date.parse(value)), 'must be an RFC 3339 instant');

export const activeWorkspacesSchema = z.object({
  data: z.array(z.object({ id, timezone: z.string().min(1).default('UTC') })),
});
export type ActiveWorkspace = z.infer<typeof activeWorkspacesSchema>['data'][number];

export const planResultSchema = z.object({
  reminders: z.array(z.object({ id, scheduled_for: instant })).default([]),
  created: count,
  superseded: count,
});
export type PlanResult = z.infer<typeof planResultSchema>;

export const DISPATCH_STATUSES = ['queued', 'skipped', 'rescheduled', 'too_early', 'already_processed'] as const;
export type DispatchStatus = (typeof DISPATCH_STATUSES)[number];

export const REMINDER_SKIP_REASONS = [
  'invoice_paid',
  'invoice_void',
  'invoice_uncollectible',
  'invoice_disputed',
  'invoice_paused',
  'client_paused',
  'recipient_opt_out',
  'no_recipient',
  'superseded',
  'limit_reached',
  'policy_changed',
] as const;

export const dispatchResultSchema = z.object({
  status: z.enum(DISPATCH_STATUSES),
  reason: z.string().nullish(),
  scheduled_for: instant.nullish(),
  current: z.string().nullish(),
});
export type DispatchResult = z.infer<typeof dispatchResultSchema>;

export const riskResultSchema = z.object({ clients: count, invoices: count, band_changes: count, duration_ms: count });
export type RiskResult = z.infer<typeof riskResultSchema>;

export const digestResultSchema = z.object({ workspaces: count, messages: count });
export type DigestResult = z.infer<typeof digestResultSchema>;

export const syncCandidatesSchema = z.object({ data: z.array(z.object({ id, workspace_id: id })) });
export type SyncCandidate = z.infer<typeof syncCandidatesSchema>['data'][number];

export const syncResultSchema = z.object({ recorded: count, deactivated: z.boolean().default(false) });
export type SyncResult = z.infer<typeof syncResultSchema>;

export const escalationResultSchema = z.object({
  escalated: count,
  confirmations_reminded: count,
  confirmations_expired: count,
  receiving_details_activated: count,
});
export type EscalationResult = z.infer<typeof escalationResultSchema>;

export const importBatchResultSchema = z.object({
  processed: count,
  imported: count,
  skipped: count,
  failed: count,
  done: z.boolean(),
  next_offset: z.number().int().nonnegative(),
});
export type ImportBatchResult = z.infer<typeof importBatchResultSchema>;

export const purgeResultSchema = z.object({ purged: z.record(z.string(), z.number().int().nonnegative()).default({}) });
export type PurgeResult = z.infer<typeof purgeResultSchema>;

export const botButtonSchema = z
  .object({
    text: z.string().trim().min(1).max(64),
    url: z.string().url().nullish(),
    callback: z.string().trim().min(1).max(64).nullish(),
  })
  .refine((button) => Boolean(button.url ?? button.callback), { message: 'button needs url or callback' });
export type BotButton = z.infer<typeof botButtonSchema>;

export const BOT_VIEWS = ['overdue', 'due', 'approvals', 'client', 'digest', 'changes', 'payout'] as const;
export type BotViewName = (typeof BOT_VIEWS)[number];

export const botViewSchema = z
  .object({
    text_html: z.string().min(1),
    buttons: z.array(botButtonSchema).max(10).default([]),
    ok: z.boolean().optional(),
    code: z.string().min(1).max(64).nullish(),
    status: z.string().nullish(),
    number: z.string().min(1).max(64).optional(),
  })
  .passthrough();
export type BotView = z.infer<typeof botViewSchema>;

export type BotResult = BotView | { code: 'not_linked' };

export const DECISION_CODES = [
  'confirm_required',
  'reason_required',
  'web_only',
  'is_requester',
  'already_decided',
  'details_changed',
  'not_pending',
  'not_eligible_yet',
  'second_factor_missing',
  'forbidden',
] as const;

export const REMIND_CODES = ['confirm_required', 'recent_reminder', 'recipient_opted_out', 'not_eligible', 'forbidden', 'not_found'] as const;

export interface PayoutDecisionInput {
  telegram_user_id: number;
  decision: 'approve' | 'reject';
  fp8: string;
  comment?: string;
  confirmed: boolean;
}

export interface RemindInput {
  telegram_user_id: number;
  invoice?: string;
  invoice_id?: number;
  confirmed: boolean;
  force?: boolean;
}

export interface PayflowApi {
  activeWorkspaces(requestId?: string): Promise<ActiveWorkspace[]>;
  planReminders(workspaceId: number, now: string | null, requestId?: string): Promise<PlanResult>;
  dispatchReminder(reminderId: number, expectedScheduledFor: string, now: string | null, requestId?: string): Promise<DispatchResult>;
  recomputeRisk(workspaceId: number, now: string | null, requestId?: string): Promise<RiskResult>;
  runDigests(now: string | null, requestId?: string): Promise<DigestResult>;
  syncCandidates(limit: number, requestId?: string): Promise<SyncCandidate[]>;
  syncPaymentLink(paymentLinkId: number, requestId?: string): Promise<SyncResult>;
  runEscalations(now: string | null, requestId?: string): Promise<EscalationResult>;
  processImportBatch(importId: number, offset: number, limit: number, requestId?: string): Promise<ImportBatchResult>;
  purge(now: string | null, requestId?: string): Promise<PurgeResult>;
  botView(view: BotViewName, telegramUserId: number, arg: string | null, requestId?: string): Promise<BotResult>;
  payoutDecision(payoutId: number, input: PayoutDecisionInput, requestId?: string): Promise<BotResult>;
  remindInvoice(input: RemindInput, requestId?: string): Promise<BotResult>;
  acknowledgeAlert(alertId: number, telegramUserId: number, requestId?: string): Promise<BotResult>;
}

export class PayflowWebClient implements PayflowApi {
  constructor(private readonly target: InternalTarget) {}

  async activeWorkspaces(requestId?: string): Promise<ActiveWorkspace[]> {
    const data = await this.call('GET', '/workspaces', { query: { active: 1 }, requestId });
    return activeWorkspacesSchema.parse(data).data;
  }

  async planReminders(workspaceId: number, now: string | null, requestId?: string): Promise<PlanResult> {
    const data = await this.call('POST', `/workspaces/${workspaceId}/reminders/plan`, { body: withNow({}, now), requestId });
    return planResultSchema.parse(data);
  }

  async dispatchReminder(reminderId: number, expectedScheduledFor: string, now: string | null, requestId?: string): Promise<DispatchResult> {
    const body = withNow({ expected_scheduled_for: expectedScheduledFor }, now);
    const data = await this.call('POST', `/reminders/${reminderId}/dispatch`, { body, requestId });
    return dispatchResultSchema.parse(data);
  }

  async recomputeRisk(workspaceId: number, now: string | null, requestId?: string): Promise<RiskResult> {
    const data = await this.call('POST', `/workspaces/${workspaceId}/risk/recompute`, { body: withNow({}, now), requestId });
    return riskResultSchema.parse(data);
  }

  async runDigests(now: string | null, requestId?: string): Promise<DigestResult> {
    return digestResultSchema.parse(await this.call('POST', '/digests/run', { body: withNow({}, now), requestId }));
  }

  async syncCandidates(limit: number, requestId?: string): Promise<SyncCandidate[]> {
    const data = await this.call('GET', '/payment-links/sync-candidates', { query: { limit }, requestId });
    return syncCandidatesSchema.parse(data).data;
  }

  async syncPaymentLink(paymentLinkId: number, requestId?: string): Promise<SyncResult> {
    return syncResultSchema.parse(await this.call('POST', `/payment-links/${paymentLinkId}/sync`, { body: {}, requestId }));
  }

  async runEscalations(now: string | null, requestId?: string): Promise<EscalationResult> {
    return escalationResultSchema.parse(await this.call('POST', '/payouts/escalations/run', { body: withNow({}, now), requestId }));
  }

  async processImportBatch(importId: number, offset: number, limit: number, requestId?: string): Promise<ImportBatchResult> {
    const data = await this.call('POST', `/imports/${importId}/process-batch`, { body: { offset, limit }, requestId });
    return importBatchResultSchema.parse(data);
  }

  async purge(now: string | null, requestId?: string): Promise<PurgeResult> {
    return purgeResultSchema.parse(await this.call('POST', '/maintenance/purge', { body: withNow({}, now), requestId }));
  }

  async botView(view: BotViewName, telegramUserId: number, arg: string | null, requestId?: string): Promise<BotResult> {
    return this.bot('GET', `/bot/views/${view}`, { query: { telegram_user_id: telegramUserId, arg }, requestId });
  }

  async payoutDecision(payoutId: number, input: PayoutDecisionInput, requestId?: string): Promise<BotResult> {
    return this.bot('POST', `/bot/payouts/${payoutId}/decision`, { body: input, requestId });
  }

  async remindInvoice(input: RemindInput, requestId?: string): Promise<BotResult> {
    return this.bot('POST', '/bot/invoices/remind', { body: input, requestId });
  }

  async acknowledgeAlert(alertId: number, telegramUserId: number, requestId?: string): Promise<BotResult> {
    return this.bot('POST', `/bot/alerts/${alertId}/acknowledge`, { body: { telegram_user_id: telegramUserId }, requestId });
  }

  private async call(
    method: HttpMethod,
    path: string,
    options: { query?: Record<string, QueryValue>; body?: unknown; requestId?: string | undefined },
  ): Promise<unknown> {
    const response = await internalFetch(this.target, {
      method,
      path: `${PAYFLOW_INTERNAL_PREFIX}${path}`,
      ...(options.query ? { query: options.query } : {}),
      ...(options.body !== undefined ? { body: options.body } : {}),
      ...(options.requestId ? { requestId: options.requestId } : {}),
    });
    return response.data;
  }

  private async bot(
    method: HttpMethod,
    path: string,
    options: { query?: Record<string, QueryValue>; body?: unknown; requestId?: string | undefined },
  ): Promise<BotResult> {
    try {
      return botViewSchema.parse(await this.call(method, path, options));
    } catch (error) {
      if (error instanceof UpstreamHttpError) {
        const handled = botErrorResult(error);
        if (handled) return handled;
      }
      throw error;
    }
  }
}

export function botErrorResult(error: UpstreamHttpError): BotResult | null {
  if (![403, 404, 409, 410, 422, 423].includes(error.status)) return null;
  const body = error.body;
  if (body && typeof body === 'object') {
    const view = botViewSchema.safeParse(body);
    if (view.success) return view.data;
    if ((body as { code?: unknown }).code === 'not_linked') return { code: 'not_linked' };
  }
  return null;
}

export function isNotLinked(result: BotResult): result is { code: 'not_linked' } {
  return result.code === 'not_linked' && !('text_html' in result);
}

function withNow(body: Record<string, unknown>, now: string | null): Record<string, unknown> {
  return now === null ? body : { ...body, now };
}
