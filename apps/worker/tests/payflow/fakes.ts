import { vi } from 'vitest';
import type { BotView, PayflowApi } from '../../src/domain/payflow/api.js';
import type { JobContext } from '../../src/jobs/types.js';
import { createSilentLogger } from '../../src/logger.js';

export function view(text_html: string, buttons: BotView['buttons'] = [], extra: Partial<BotView> = {}): BotView {
  return { text_html, buttons, ...extra };
}

export function fakePayflowApi() {
  return {
    activeWorkspaces: vi.fn<PayflowApi['activeWorkspaces']>(async () => [
      { id: 1, timezone: 'Europe/Riga' },
      { id: 2, timezone: 'America/New_York' },
    ]),
    planReminders: vi.fn<PayflowApi['planReminders']>(async () => ({ reminders: [], created: 0, superseded: 0 })),
    dispatchReminder: vi.fn<PayflowApi['dispatchReminder']>(async () => ({ status: 'queued' })),
    recomputeRisk: vi.fn<PayflowApi['recomputeRisk']>(async () => ({ clients: 8, invoices: 12, band_changes: 1, duration_ms: 40 })),
    runDigests: vi.fn<PayflowApi['runDigests']>(async () => ({ workspaces: 1, messages: 4 })),
    syncCandidates: vi.fn<PayflowApi['syncCandidates']>(async () => [
      { id: 11, workspace_id: 1 },
      { id: 12, workspace_id: 1 },
    ]),
    syncPaymentLink: vi.fn<PayflowApi['syncPaymentLink']>(async () => ({ recorded: 1, deactivated: true })),
    runEscalations: vi.fn<PayflowApi['runEscalations']>(async () => ({ escalated: 1, confirmations_reminded: 2, confirmations_expired: 0, receiving_details_activated: 1 })),
    processImportBatch: vi.fn<PayflowApi['processImportBatch']>(async (_id, offset) => ({
      processed: 200,
      imported: 190,
      skipped: 5,
      failed: 5,
      done: offset >= 200,
      next_offset: offset + 200,
    })),
    purge: vi.fn<PayflowApi['purge']>(async () => ({ purged: { 'payflow.reminders': 3, 'payflow.ai_queries': 2 } })),
    botView: vi.fn<PayflowApi['botView']>(async () => view('<b>Overdue</b>')),
    payoutDecision: vi.fn<PayflowApi['payoutDecision']>(async () => view('Approved')),
    remindInvoice: vi.fn<PayflowApi['remindInvoice']>(async () => view('Reminder queued')),
    acknowledgeAlert: vi.fn<PayflowApi['acknowledgeAlert']>(async () => view('Acknowledged by Anna Petrova')),
  } satisfies PayflowApi;
}

export function jobContext(overrides: Partial<JobContext> = {}): JobContext {
  return {
    queue: 'pf-test',
    name: 'job',
    jobId: 'job-1',
    attempt: 1,
    maxAttempts: 5,
    isFinalAttempt: false,
    requestId: 'req-1',
    logger: createSilentLogger(),
    ...overrides,
  };
}
