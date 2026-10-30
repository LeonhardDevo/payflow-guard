import { z } from 'zod';
import { UpstreamHttpError } from '../../../../clients/errors.js';
import { ToolError, type Tool, type ToolContext, type ToolResult, type ToolRegistry } from '../../../tools/types.js';
import { PAYFLOW_TOOL_SPECS, type PayflowToolSpec } from './definitions.js';
import { validateArgs } from './schema.js';

const IBAN_RE = /\b([A-Z]{2}\d{2})[ ]?((?:[A-Z0-9][ ]?){10,30})\b/g;
const LONG_ACCOUNT_RE = /\b\d{9,30}\b/g;

const successSchema = z
  .object({
    data: z.unknown(),
    summary: z.string().default(''),
    resolved: z.object({ kind: z.string(), id: z.number(), name: z.string(), score: z.number() }).passthrough().nullish(),
    as_of: z.string().nullish(),
  })
  .passthrough();

const failureSchema = z
  .object({
    error: z.enum(['not_found', 'ambiguous', 'forbidden']),
    summary: z.string().default(''),
    candidates: z.array(z.object({ id: z.number(), name: z.string() }).passthrough()).max(20).nullish(),
  })
  .passthrough();

export function maskAccountNumbers(text: string): string {
  return text
    .replace(IBAN_RE, (match) => `•••• ${match.replace(/\s/g, '').slice(-4)}`)
    .replace(LONG_ACCOUNT_RE, (match) => `•••• ${match.slice(-4)}`);
}

export function scrub(value: unknown): unknown {
  if (typeof value === 'string') return maskAccountNumbers(value);
  if (Array.isArray(value)) return value.map(scrub);
  if (value && typeof value === 'object') {
    const result: Record<string, unknown> = {};
    for (const [key, entry] of Object.entries(value as Record<string, unknown>)) {
      if (/^(?:holder_name|account_holder|iban|account_number|routing_number|full_iban)$/i.test(key)) continue;
      result[key] = scrub(entry);
    }
    return result;
  }
  return value;
}

export class PayflowDataTool implements Tool {
  readonly name: string;
  readonly description: string;
  readonly parameters: Record<string, unknown>;
  readonly requiredPermission: string;
  readonly dataTool = true;

  constructor(private readonly spec: PayflowToolSpec) {
    this.name = spec.name;
    this.description = spec.description;
    this.parameters = spec.parameters as unknown as Record<string, unknown>;
    this.requiredPermission = spec.requiredPermission;
  }

  async run(ctx: ToolContext, args: Record<string, unknown>): Promise<ToolResult> {
    const validated = validateArgs(this.spec.parameters, args);
    if (!validated.ok) throw new ToolError(`invalid arguments for ${this.name}: ${validated.errors.join('; ')}`);
    if (ctx.user.id === null) throw new ToolError(`${this.name} needs a signed-in user`);
    const query: Record<string, string | number | boolean> = { workspace_id: ctx.workspaceId, user_id: ctx.user.id, locale: ctx.locale };
    for (const [key, value] of Object.entries(validated.value)) {
      if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') query[key] = value;
    }
    let body: unknown;
    try {
      body = await ctx.web.getJson(this.spec.path, query, ctx.requestId ?? undefined);
    } catch (error) {
      if (error instanceof UpstreamHttpError && error.status === 403) {
        const failure = failureSchema.safeParse(error.body);
        const summary = failure.success ? failure.data.summary : '';
        return { content: JSON.stringify({ error: 'forbidden', summary: maskAccountNumbers(summary) }), hasData: false };
      }
      throw error;
    }
    const failure = failureSchema.safeParse(body);
    if (failure.success) {
      return {
        content: JSON.stringify(
          scrub({
            error: failure.data.error,
            summary: failure.data.summary,
            ...(failure.data.candidates ? { candidates: failure.data.candidates.slice(0, 3) } : {}),
          }),
        ),
        hasData: failure.data.error !== 'forbidden',
      };
    }
    const success = successSchema.safeParse(body);
    if (!success.success) throw new ToolError(`${this.name} returned an unexpected payload`);
    const envelope = scrub({
      summary: success.data.summary,
      data: success.data.data,
      as_of: success.data.as_of ?? new Date().toISOString(),
      ...(success.data.resolved ? { resolved: success.data.resolved } : {}),
    });
    return { content: JSON.stringify(envelope), hasData: true };
  }
}

export function registerPayflowTools(registry: ToolRegistry): void {
  for (const spec of PAYFLOW_TOOL_SPECS) registry.register(new PayflowDataTool(spec));
}
