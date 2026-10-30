import type { FastifyInstance, FastifyPluginAsync, FastifyReply, FastifyRequest } from 'fastify';
import type { z } from 'zod';
import { SERVICE_HEADER, SIGNATURE_HEADER, verifySignature, type ServiceName } from '../../clients/signing.js';
import { IngestError } from '../ingest/service.js';
import { DocumentOwnershipError } from '../retrieval/store.js';
import type { RagService } from '../service.js';
import { SettingsValidationError } from '../settings/store.js';
import {
  aiSettingsBodySchema,
  chatBodySchema,
  ingestBodySchema,
  memoryPruneBodySchema,
  positiveId,
  providerKeyBodySchema,
  reembedBodySchema,
  searchBodySchema,
  usageQuerySchema,
  validationPayload,
  workspaceQuerySchema,
} from './schemas.js';

const BODY_LIMIT = 2 * 1024 * 1024;

export type RagDomainRoutes = (app: FastifyInstance, rag: RagService) => Promise<void> | void;

export interface RagRouteDeps {
  rag: RagService;
  peerSecrets: Partial<Record<ServiceName, string>>;
  toleranceS?: number;
  domainRoutes?: readonly RagDomainRoutes[];
}

export class BodyValidationError extends Error {
  constructor(readonly payload: ReturnType<typeof validationPayload>) {
    super(payload.detail);
  }
}

function rawBody(request: FastifyRequest): string {
  return Buffer.isBuffer(request.body) ? request.body.toString('utf8') : '';
}

function parseJson(request: FastifyRequest): unknown {
  const raw = rawBody(request);
  if (raw.trim() === '') return {};
  try {
    return JSON.parse(raw) as unknown;
  } catch {
    throw new BodyValidationError({ detail: 'body: invalid JSON', message: 'validation failed', errors: { body: ['invalid JSON'] } });
  }
}

export function validate<S extends z.ZodType>(schema: S, value: unknown): z.output<S> {
  const parsed = schema.safeParse(value);
  if (!parsed.success) throw new BodyValidationError(validationPayload(parsed.error));
  return parsed.data;
}

export function body<S extends z.ZodType>(request: FastifyRequest, schema: S): z.output<S> {
  const value = parseJson(request);
  return validate(schema, Array.isArray(value) && value.length === 0 ? {} : value);
}

export function callerOf(request: FastifyRequest): ServiceName {
  return String(request.headers[SERVICE_HEADER.toLowerCase()] ?? '')
    .trim()
    .toLowerCase() as ServiceName;
}

export function requestIdOf(request: FastifyRequest): string {
  const header = request.headers['x-request-id'];
  return typeof header === 'string' && /^[A-Za-z0-9._:-]{8,64}$/.test(header) ? header : request.id;
}

function pathId(request: FastifyRequest, name: string): number | null {
  const parsed = positiveId.safeParse((request.params as Record<string, string>)[name]);
  return parsed.success ? parsed.data : null;
}

export function ragRoutes(deps: RagRouteDeps): FastifyPluginAsync {
  return async (app) => {
    app.removeAllContentTypeParsers();
    app.addContentTypeParser('*', { parseAs: 'buffer', bodyLimit: BODY_LIMIT }, (_request, payload, done) => done(null, payload));

    app.addHook('preHandler', async (request: FastifyRequest, reply: FastifyReply) => {
      const service = callerOf(request);
      const secret = deps.peerSecrets[service];
      const header = request.headers[SIGNATURE_HEADER.toLowerCase()];
      const result = secret
        ? verifySignature({
            secret,
            header: typeof header === 'string' ? header : null,
            method: request.method,
            pathWithQuery: request.url,
            body: rawBody(request),
            ...(deps.toleranceS !== undefined ? { toleranceS: deps.toleranceS } : {}),
          })
        : ({ ok: false, reason: 'missing' } as const);
      if (!result.ok) {
        request.log.warn({ service, reason: result.reason, path: request.url.split('?')[0] }, 'internal signature rejected');
        return reply.code(401).send({ error: 'invalid_signature', detail: 'invalid_signature' });
      }
    });

    app.setErrorHandler((error: Error & { statusCode?: number }, request, reply) => {
      if (error instanceof BodyValidationError) return reply.code(422).send(error.payload);
      if (error instanceof SettingsValidationError) {
        return reply.code(422).send({ detail: error.message, message: error.message, errors: { [error.field]: [error.message] } });
      }
      if (error instanceof DocumentOwnershipError) return reply.code(409).send({ error: 'conflict', detail: error.message, message: error.message });
      const status = error.statusCode && error.statusCode >= 400 ? error.statusCode : 500;
      if (status >= 500) request.log.error({ err: error }, 'rag request failed');
      return reply.code(status).send({ error: status >= 500 ? 'internal_error' : error.message });
    });

    const rag = deps.rag;

    app.post('/v1/chat', async (request) => rag.chat(body(request, chatBodySchema), requestIdOf(request)));

    app.post('/v1/search', async (request) => {
      const input = body(request, searchBodySchema);
      const filters = input.filters;
      return rag.search({
        workspace_id: input.workspace_id,
        query: input.query,
        k: input.k,
        filters: filters
          ? { department: filters.department, tags: filters.tags, documentIds: filters.document_ids, roles: filters.roles ?? null }
          : null,
        ai_external_allowed: input.ai_external_allowed ?? null,
      });
    });

    app.post('/v1/documents/:id/ingest', async (request, reply) => {
      const id = pathId(request, 'id');
      if (id === null) return reply.code(404).send({ error: 'not_found', detail: 'document not found' });
      const input = body(request, ingestBodySchema);
      try {
        return await rag.ingest(id, input, requestIdOf(request));
      } catch (error) {
        if (error instanceof IngestError) {
          return reply
            .code(error.statusCode)
            .send({ error: 'ingest_failed', detail: error.message, message: error.message, document_id: id });
        }
        throw error;
      }
    });

    app.get('/v1/documents/:id/chunks', async (request, reply) => {
      const id = pathId(request, 'id');
      if (id === null) return reply.code(404).send({ error: 'not_found', detail: 'document not found' });
      const query = validate(workspaceQuerySchema, request.query);
      return rag.chunks(id, query.workspace_id, query.limit);
    });

    app.delete('/v1/documents/:id', async (request, reply) => {
      const id = pathId(request, 'id');
      if (id === null) return reply.code(404).send({ error: 'not_found', detail: 'document not found' });
      const query = validate(workspaceQuerySchema.pick({ workspace_id: true }), request.query);
      return rag.deleteDocument(id, query.workspace_id);
    });

    app.post('/v1/workspaces/:id/reembed', async (request, reply) => {
      const id = pathId(request, 'id');
      if (id === null) return reply.code(404).send({ error: 'not_found', detail: 'workspace not found' });
      return rag.reembedWorkspace(id, body(request, reembedBodySchema).ai_external_allowed ?? null);
    });

    app.delete('/v1/workspaces/:id', async (request, reply) => {
      const id = pathId(request, 'id');
      if (id === null) return reply.code(404).send({ error: 'not_found', detail: 'workspace not found' });
      return rag.deleteWorkspace(id);
    });

    app.delete('/v1/users/:id', async (request, reply) => {
      const id = pathId(request, 'id');
      if (id === null) return reply.code(404).send({ error: 'not_found', detail: 'user not found' });
      return rag.deleteUser(id);
    });

    app.post('/v1/privacy/prune-memory', async (request) => {
      const input = body(request, memoryPruneBodySchema);
      return rag.pruneMemory(input.default_days, input.workspaces);
    });

    app.get('/v1/admin/ai/providers', async (request) => {
      const locale = (request.query as Record<string, unknown>).locale;
      return rag.providers(typeof locale === 'string' ? locale.slice(0, 16) : undefined);
    });

    app.get('/v1/admin/ai/providers/:provider/models', async (request, reply) => {
      const provider = String((request.params as Record<string, string>).provider ?? '');
      const rawRefresh = (request.query as Record<string, unknown>).refresh;
      const refresh = typeof rawRefresh === 'string' && ['1', 'true', 'yes'].includes(rawRefresh.toLowerCase());
      const result = await rag.models(provider, refresh);
      if (result === null) return reply.code(404).send({ error: 'not_found', detail: 'unknown provider' });
      return result;
    });

    app.get('/v1/admin/ai/settings', async () => rag.getSettings());

    app.put('/v1/admin/ai/settings', async (request) => rag.putSettings(body(request, aiSettingsBodySchema)));

    app.put('/v1/admin/ai/keys/:provider', async (request, reply) => {
      const provider = String((request.params as Record<string, string>).provider ?? '');
      const input = body(request, providerKeyBodySchema);
      const result = await rag.putKey(provider, input.api_key ?? null);
      if (result === 'unknown') return reply.code(404).send({ error: 'not_found', detail: 'unknown provider' });
      if (result === 'keyless') {
        const message = 'this provider does not use an API key';
        return reply.code(422).send({ detail: message, message, errors: { api_key: [message] } });
      }
      return result;
    });

    app.post('/v1/admin/ai/test', async (request) => rag.testProvider(requestIdOf(request)));

    app.get('/v1/admin/ai/usage', async (request) => rag.usage(validate(usageQuerySchema, request.query).days));

    for (const register of deps.domainRoutes ?? []) await register(app, rag);
  };
}
