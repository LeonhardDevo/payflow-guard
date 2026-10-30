import { describe, expect, it } from 'vitest';
import { UpstreamHttpError } from '../../../src/clients/errors.js';
import { verifySignature } from '../../../src/clients/signing.js';
import { createSilentLogger } from '../../../src/logger.js';
import { PAYFLOW_TOOL_SPECS } from '../../../src/rag/domain/payflow/tools/definitions.js';
import { maskAccountNumbers, PayflowDataTool } from '../../../src/rag/domain/payflow/tools/payflowTool.js';
import { validateArgs } from '../../../src/rag/domain/payflow/tools/schema.js';
import { buildRegistry } from '../../../src/rag/tools/index.js';
import { ChatUser, ToolError, type ToolContext } from '../../../src/rag/tools/types.js';
import { assertWebPathAllowed, RagWebClient, type RagWebApi } from '../../../src/rag/web/client.js';

const CONTRACT_SCHEMAS: Record<string, string> = {
  list_overdue_invoices:
    '{"type":"object","properties":{"client_query":{"type":"string"},"sort":{"enum":["days_overdue","amount"],"default":"days_overdue"},"currency":{"type":"string","pattern":"^[A-Z]{3}$"},"limit":{"type":"integer","minimum":1,"maximum":10,"default":5}},"additionalProperties":false}',
  get_invoice: '{"type":"object","properties":{"number":{"type":"string","minLength":3,"maxLength":40}},"required":["number"],"additionalProperties":false}',
  get_client_profile: '{"type":"object","properties":{"client_query":{"type":"string","minLength":2}},"required":["client_query"],"additionalProperties":false}',
  get_reminder_schedule:
    '{"type":"object","properties":{"client_query":{"type":"string"},"invoice_number":{"type":"string"}},"anyOf":[{"required":["client_query"]},{"required":["invoice_number"]}],"additionalProperties":false}',
  get_contract_terms: '{"type":"object","properties":{"client_query":{"type":"string","minLength":2}},"required":["client_query"],"additionalProperties":false}',
  list_bank_detail_changes:
    '{"type":"object","properties":{"vendor_query":{"type":"string"},"days":{"type":"integer","minimum":1,"maximum":365,"default":90},"status":{"enum":["pending_verification","verified","rejected","any"],"default":"any"}},"additionalProperties":false}',
  list_pending_approvals: '{"type":"object","properties":{},"additionalProperties":false}',
  get_ar_summary: '{"type":"object","properties":{"currency":{"type":"string","pattern":"^[A-Z]{3}$"}},"additionalProperties":false}',
  list_late_payers: '{"type":"object","properties":{"limit":{"type":"integer","minimum":1,"maximum":10,"default":5}},"additionalProperties":false}',
};

const PERMISSIONS: Record<string, string> = {
  list_overdue_invoices: 'invoices.view',
  get_invoice: 'invoices.view',
  get_client_profile: 'clients.view',
  get_reminder_schedule: 'invoices.view',
  get_contract_terms: 'clients.view',
  list_bank_detail_changes: 'vendors.view',
  list_pending_approvals: 'payouts.view',
  get_ar_summary: 'analytics.view',
  list_late_payers: 'clients.view',
};

class ScriptedWeb implements RagWebApi {
  readonly calls: Array<{ path: string; query: Record<string, unknown> | undefined }> = [];
  constructor(private readonly respond: (path: string) => unknown) {}
  async downloadDocument(): Promise<never> {
    throw new Error('unused');
  }
  async reportDocumentStatus(): Promise<void> {}
  async logAiQuery(): Promise<void> {}
  async getJson<T>(path: string, query?: Record<string, unknown>): Promise<T> {
    this.calls.push({ path, query });
    const value = this.respond(path);
    if (value instanceof Error) throw value;
    return value as T;
  }
  async postJson<T>(): Promise<T> {
    return {} as T;
  }
}

function context(web: RagWebApi, permissions: string[] = Object.values(PERMISSIONS), userId: number | null = 7): ToolContext {
  return {
    workspaceId: 3,
    user: new ChatUser(userId, 'finance', 'ru', permissions),
    web,
    requestId: 'req-1',
    locale: 'ru',
    logger: createSilentLogger(),
  } as unknown as ToolContext;
}

const tool = (name: string): PayflowDataTool => new PayflowDataTool(PAYFLOW_TOOL_SPECS.find((spec) => spec.name === name)!);

describe('PayFlow RAG tools', () => {
  it('declares exactly the nine contract tools with their schemas and permissions', () => {
    expect(PAYFLOW_TOOL_SPECS.map((spec) => spec.name).sort()).toEqual(Object.keys(CONTRACT_SCHEMAS).sort());
    for (const spec of PAYFLOW_TOOL_SPECS) {
      expect(spec.parameters, spec.name).toEqual(JSON.parse(CONTRACT_SCHEMAS[spec.name]!));
      expect(spec.requiredPermission, spec.name).toBe(PERMISSIONS[spec.name]);
      expect(() => assertWebPathAllowed('GET', spec.path)).not.toThrow();
      expect(() => assertWebPathAllowed('POST', spec.path)).toThrow();
    }
  });

  it('registers after search_documents and offers only permitted tools', () => {
    const registry = buildRegistry();
    expect(registry.all().map((entry) => entry.name)).toEqual(['search_documents', ...PAYFLOW_TOOL_SPECS.map((spec) => spec.name)]);
    const member = new ChatUser(9, 'member', 'en', ['invoices.view', 'clients.view']);
    expect(registry.availableFor(member).map((entry) => entry.name).sort()).toEqual(
      ['get_client_profile', 'get_contract_terms', 'get_invoice', 'get_reminder_schedule', 'list_late_payers', 'list_overdue_invoices', 'search_documents'].sort(),
    );
    expect(registry.all().filter((entry) => entry.name !== 'search_documents').every((entry) => entry.dataTool)).toBe(true);
  });

  it('validates arguments against the JSON schema and applies defaults', () => {
    const overdue = PAYFLOW_TOOL_SPECS.find((spec) => spec.name === 'list_overdue_invoices')!.parameters;
    expect(validateArgs(overdue, {})).toEqual({ ok: true, value: { sort: 'days_overdue', limit: 5 } });
    expect(validateArgs(overdue, { limit: 11 }).ok).toBe(false);
    expect(validateArgs(overdue, { currency: 'usd' }).ok).toBe(false);
    expect(validateArgs(overdue, { sort: 'name' }).ok).toBe(false);
    expect(validateArgs(overdue, { extra: true }).ok).toBe(false);
    const schedule = PAYFLOW_TOOL_SPECS.find((spec) => spec.name === 'get_reminder_schedule')!.parameters;
    expect(validateArgs(schedule, {}).ok).toBe(false);
    expect(validateArgs(schedule, { invoice_number: 'NS-2026-0118' }).ok).toBe(true);
  });

  it('calls web with the workspace, user, locale and validated arguments', async () => {
    const web = new ScriptedWeb(() => ({ data: { items: [], totals: [], count: 0 }, summary: 'Нет просроченных счетов.', as_of: '2026-10-23T09:00:00Z' }));
    const result = await tool('list_overdue_invoices').run(context(web), { client_query: ' Helios ' });
    expect(web.calls).toEqual([
      {
        path: '/internal/v1/payflow/ai/invoices/overdue',
        query: { workspace_id: 3, user_id: 7, locale: 'ru', client_query: 'Helios', sort: 'days_overdue', limit: 5 },
      },
    ]);
    expect(result.hasData).toBe(true);
    expect(JSON.parse(result.content)).toEqual({
      summary: 'Нет просроченных счетов.',
      data: { items: [], totals: [], count: 0 },
      as_of: '2026-10-23T09:00:00Z',
    });
  });

  it('rejects invalid arguments and anonymous users before calling web', async () => {
    const web = new ScriptedWeb(() => ({}));
    await expect(tool('get_invoice').run(context(web), {})).rejects.toBeInstanceOf(ToolError);
    await expect(tool('get_invoice').run(context(web, undefined, null), { number: 'NS-2026-0118' })).rejects.toBeInstanceOf(ToolError);
    expect(web.calls).toHaveLength(0);
  });

  it('never returns full account numbers or holder names', async () => {
    const web = new ScriptedWeb(() => ({
      summary: 'PixelForge: новый счёт LT58 3250 0677 4120 0519, держатель скрыт.',
      data: {
        items: [
          {
            vendor: 'PixelForge Studio',
            after: { bank_country: 'LT', last4: '0519', holder_name: 'PF Studio Payments UAB', iban: 'LT583250067741200519', holder_matches_vendor: false },
            note: 'old account PL61109010140000071219812874, routing 000123454471',
          },
        ],
      },
      as_of: '2026-10-23T09:00:00Z',
    }));
    const result = await tool('list_bank_detail_changes').run(context(web), { vendor_query: 'PixelForge' });
    expect(result.content).not.toMatch(/[A-Z]{2}\d{2}[A-Z0-9]{10,30}/);
    expect(result.content).not.toContain('PF Studio Payments UAB');
    expect(result.content).not.toContain('000123454471');
    expect(result.content).toContain('•••• 0519');
    expect(maskAccountNumbers('IBAN GE44TB7741936582010047')).toBe('IBAN •••• 0047');
  });

  it('passes not_found and ambiguous envelopes through and hides forbidden details', async () => {
    const ambiguous = new ScriptedWeb(() => ({ error: 'ambiguous', summary: 'Найдено несколько клиентов.', candidates: [{ id: 1, name: 'Helios Media Group' }, { id: 2, name: 'Helios Labs' }] }));
    const result = await tool('get_client_profile').run(context(ambiguous), { client_query: 'Helios' });
    expect(JSON.parse(result.content)).toMatchObject({ error: 'ambiguous', candidates: [{ name: 'Helios Media Group' }, { name: 'Helios Labs' }] });
    expect(result.hasData).toBe(true);

    const forbidden = new ScriptedWeb(
      () => new UpstreamHttpError('web', 'GET', '/internal/v1/payflow/ai/payouts/pending', 403, { error: 'forbidden', summary: 'Нет доступа.' }),
    );
    const denied = await tool('list_pending_approvals').run(context(forbidden), {});
    expect(JSON.parse(denied.content)).toEqual({ error: 'forbidden', summary: 'Нет доступа.' });
    expect(denied.hasData).toBe(false);

    const failing = new ScriptedWeb(() => new UpstreamHttpError('web', 'GET', '/x', 500, null));
    await expect(tool('get_ar_summary').run(context(failing), {})).rejects.toBeInstanceOf(UpstreamHttpError);
  });

  it('signs the F.3 calls as service ai with the web<->rag pair key', async () => {
    const seen: Array<{ url: string; headers: Record<string, string> }> = [];
    const fakeFetch = (async (url: string, init: { headers: Record<string, string> }) => {
      seen.push({ url, headers: init.headers });
      return new Response(JSON.stringify({ data: { items: [] }, summary: '', as_of: '2026-10-23T09:00:00Z' }), {
        status: 200,
        headers: { 'content-type': 'application/json' },
      });
    }) as unknown as typeof fetch;
    const client = new RagWebClient({ service: 'web', caller: 'ai', baseUrl: 'http://web.test', secret: 'web-ai-pair-key-0123456789abcdef', timeoutMs: 1000, fetch: fakeFetch }, 1024);
    await tool('list_late_payers').run(context(client), {});
    const call = seen[0]!;
    const pathWithQuery = call.url.replace('http://web.test', '');
    expect(pathWithQuery).toBe('/internal/v1/payflow/ai/clients/late-payers?workspace_id=3&user_id=7&locale=ru&limit=5');
    expect(call.headers['X-Service']).toBe('ai');
    expect(verifySignature({ secret: 'web-ai-pair-key-0123456789abcdef', header: call.headers['X-Signature'], method: 'GET', pathWithQuery, body: '' })).toEqual({ ok: true });
    expect(verifySignature({ secret: 'web-worker-key-0123456789abcdef00', header: call.headers['X-Signature'], method: 'GET', pathWithQuery, body: '' }).ok).toBe(false);
    await expect(client.getJson('/internal/v1/payflow/payouts/1/decision')).rejects.toThrow(/not reachable/);
  });
});
