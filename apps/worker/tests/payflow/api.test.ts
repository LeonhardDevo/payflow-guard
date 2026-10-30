import { describe, expect, it } from 'vitest';
import { PayflowWebClient } from '../../src/domain/payflow/api.js';
import { UpstreamHttpError } from '../../src/clients/errors.js';
import { verifySignature } from '../../src/clients/signing.js';
import { ZodError } from 'zod';

const WEB_WORKER_KEY = 'web-worker-pair-key-0123456789abcdef';

interface Seen {
  method: string;
  pathWithQuery: string;
  body: string;
  headers: Record<string, string>;
}

function client(respond: (seen: Seen) => { status?: number; body: unknown }) {
  const seen: Seen[] = [];
  const fakeFetch = (async (url: string, init: { method: string; headers: Record<string, string>; body?: string }) => {
    const entry = { method: init.method, pathWithQuery: url.replace('http://web.test', ''), body: init.body ?? '', headers: init.headers };
    seen.push(entry);
    const reply = respond(entry);
    return new Response(JSON.stringify(reply.body), { status: reply.status ?? 200, headers: { 'content-type': 'application/json' } });
  }) as unknown as typeof fetch;
  return { seen, api: new PayflowWebClient({ service: 'web', baseUrl: 'http://web.test', secret: WEB_WORKER_KEY, timeoutMs: 1000, fetch: fakeFetch }) };
}

describe('PayFlow internal API client (F.1 / F.2)', () => {
  it('signs every call as the worker with the web<->worker pair key', async () => {
    const { seen, api } = client(() => ({ body: { reminders: [{ id: 41, scheduled_for: '2026-10-24T06:17:00Z' }], created: 1, superseded: 0 } }));
    const result = await api.planReminders(3, '2026-10-23T12:00:00.000Z', 'req-77');
    expect(result.reminders).toEqual([{ id: 41, scheduled_for: '2026-10-24T06:17:00Z' }]);
    const call = seen[0]!;
    expect(call).toMatchObject({ method: 'POST', pathWithQuery: '/internal/v1/payflow/workspaces/3/reminders/plan' });
    expect(JSON.parse(call.body)).toEqual({ now: '2026-10-23T12:00:00.000Z' });
    expect(call.headers['X-Service']).toBe('worker');
    expect(call.headers['X-Request-Id']).toBe('req-77');
    expect(verifySignature({ secret: WEB_WORKER_KEY, header: call.headers['X-Signature'], method: 'POST', pathWithQuery: call.pathWithQuery, body: call.body })).toEqual({ ok: true });
    expect(verifySignature({ secret: 'web-ai-pair-key-0123456789abcdef00', header: call.headers['X-Signature'], method: 'POST', pathWithQuery: call.pathWithQuery, body: call.body }).ok).toBe(false);
  });

  it('builds the F.1 paths and bodies', async () => {
    const replies: Record<string, unknown> = {
      '/internal/v1/payflow/workspaces?active=1': { data: [{ id: 3, timezone: 'Europe/Riga' }] },
      '/internal/v1/payflow/reminders/41/dispatch': { status: 'skipped', reason: 'invoice_paid' },
      '/internal/v1/payflow/workspaces/3/risk/recompute': { clients: 1, invoices: 2, band_changes: 0, duration_ms: 3 },
      '/internal/v1/payflow/digests/run': { workspaces: 1, messages: 2 },
      '/internal/v1/payflow/payment-links/sync-candidates?limit=100': { data: [{ id: 11, workspace_id: 3 }] },
      '/internal/v1/payflow/payment-links/11/sync': { recorded: 1, deactivated: false },
      '/internal/v1/payflow/payouts/escalations/run': { escalated: 0, confirmations_reminded: 0, confirmations_expired: 0, receiving_details_activated: 0 },
      '/internal/v1/payflow/imports/9/process-batch': { processed: 1, imported: 1, skipped: 0, failed: 0, done: true, next_offset: 1 },
      '/internal/v1/payflow/maintenance/purge': { purged: { 'payflow.reminders': 2 } },
    };
    const { seen, api } = client((entry) => ({ body: replies[entry.pathWithQuery] ?? {} }));
    expect(await api.activeWorkspaces()).toEqual([{ id: 3, timezone: 'Europe/Riga' }]);
    expect(await api.dispatchReminder(41, '2026-10-24T06:17:00Z', null)).toEqual({ status: 'skipped', reason: 'invoice_paid' });
    await api.recomputeRisk(3, null);
    await api.runDigests('2026-10-24T07:00:00Z');
    expect(await api.syncCandidates(100)).toEqual([{ id: 11, workspace_id: 3 }]);
    await api.syncPaymentLink(11);
    await api.runEscalations(null);
    expect(await api.processImportBatch(9, 0, 200)).toMatchObject({ done: true });
    expect(await api.purge(null)).toEqual({ purged: { 'payflow.reminders': 2 } });
    expect(JSON.parse(seen[1]!.body)).toEqual({ expected_scheduled_for: '2026-10-24T06:17:00Z' });
    expect(JSON.parse(seen[3]!.body)).toEqual({ now: '2026-10-24T07:00:00Z' });
    expect(JSON.parse(seen[7]!.body)).toEqual({ offset: 0, limit: 200 });
    expect(seen.map((entry) => entry.method)).toEqual(['GET', 'POST', 'POST', 'POST', 'GET', 'POST', 'POST', 'POST', 'POST']);
  });

  it('rejects responses that break the contract', async () => {
    const { api } = client(() => ({ body: { status: 'teleported' } }));
    await expect(api.dispatchReminder(41, '2026-10-24T06:17:00Z', null)).rejects.toBeInstanceOf(ZodError);
  });

  it('returns bot views, error views and the not-linked marker', async () => {
    const { seen, api } = client((entry) => {
      if (entry.pathWithQuery.startsWith('/internal/v1/payflow/bot/views/overdue')) return { body: { text_html: '<b>Overdue</b>', buttons: [{ text: 'Remind', callback: 'pf.rm:118' }] } };
      if (entry.pathWithQuery.startsWith('/internal/v1/payflow/bot/views/client')) return { status: 404, body: { code: 'not_linked' } };
      if (entry.pathWithQuery.includes('/decision')) return { status: 403, body: { ok: false, code: 'forbidden', text_html: 'Not allowed', buttons: [] } };
      return { status: 500, body: { message: 'boom' } };
    });
    expect(await api.botView('overdue', 700000001, null)).toEqual({ text_html: '<b>Overdue</b>', buttons: [{ text: 'Remind', callback: 'pf.rm:118' }] });
    expect(seen[0]!.pathWithQuery).toBe('/internal/v1/payflow/bot/views/overdue?telegram_user_id=700000001');
    expect(await api.botView('client', 700000001, 'Helios')).toEqual({ code: 'not_linked' });
    expect(seen[1]!.pathWithQuery).toBe('/internal/v1/payflow/bot/views/client?telegram_user_id=700000001&arg=Helios');
    expect(await api.payoutDecision(89, { telegram_user_id: 1, decision: 'approve', fp8: '1a2b3c4d', confirmed: false })).toMatchObject({ code: 'forbidden', text_html: 'Not allowed' });
    await expect(api.acknowledgeAlert(5, 1)).rejects.toBeInstanceOf(UpstreamHttpError);
  });
});
