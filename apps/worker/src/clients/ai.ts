import { z } from 'zod';
import { internalFetch, type InternalTarget } from './internalFetch.js';
import { isNotFound } from './errors.js';

export const citationSchema = z.object({
  document_id: z.union([z.number(), z.string()]),
  title: z.string(),
  page: z.number().nullish(),
  snippet: z.string().nullish(),
  url: z.string().nullish(),
});
export type Citation = z.infer<typeof citationSchema>;

export const chatActionSchema = z.object({
  type: z.string().min(1),
  label: z.string().min(1),
  payload: z.record(z.string(), z.unknown()).nullish().transform((value) => value ?? {}),
});
export type ChatAction = z.infer<typeof chatActionSchema>;

export const chatResponseSchema = z.object({
  answer_markdown: z.string().default(''),
  answer_html: z.string().default(''),
  citations: z.array(citationSchema).default([]),
  confidence: z.number().min(0).max(1).default(0),
  refused: z.boolean().default(false),
  tools_used: z.array(z.string()).default([]),
  actions: z.array(chatActionSchema).default([]),
  provider: z.string().nullish(),
  model: z.string().nullish(),
  latency_ms: z.number().nullish(),
});
export type ChatResponse = z.infer<typeof chatResponseSchema>;

export interface ChatRequest {
  workspace_id: number;
  user: { id: number; role: string; locale: 'ru' | 'en'; permissions: string[] };
  channel: 'telegram' | 'web';
  conversation_id?: string;
  message: string;
  ai_external_allowed?: boolean;
}

export interface IngestRequest {
  workspace_id: number;
  ai_external_allowed: boolean;
  title?: string;
  mime?: string;
  metadata: Record<string, unknown>;
}

const ingestResponseSchema = z
  .object({ pages: z.number().nullish(), chunks: z.number().nullish(), ocr_used: z.boolean().nullish() })
  .passthrough()
  .nullable();
export type IngestResult = z.infer<typeof ingestResponseSchema>;

export interface AiApi {
  chat(request: ChatRequest, requestId?: string): Promise<ChatResponse>;
  ingestDocument(documentId: number | string, request: IngestRequest, requestId?: string): Promise<IngestResult>;
  deleteDocument(documentId: number | string, workspaceId: number, requestId?: string): Promise<void>;
  reembedWorkspace(workspaceId: number, aiExternalAllowed: boolean, requestId?: string): Promise<void>;
  purgeWorkspace(workspaceId: number, requestId?: string): Promise<void>;
  purgeUser(userId: number, requestId?: string): Promise<void>;
  pruneMemory(retention: MemoryRetention, requestId?: string): Promise<void>;
}

export interface MemoryRetention {
  default_days: number;
  workspaces: { workspace_id: number; days: number }[];
}

export class AiClient implements AiApi {
  constructor(
    private readonly target: InternalTarget,
    private readonly timeouts: { chatMs: number; ingestMs: number },
  ) {}

  async chat(request: ChatRequest, requestId?: string): Promise<ChatResponse> {
    const response = await internalFetch(this.target, {
      method: 'POST',
      path: '/v1/chat',
      body: request,
      requestId,
      timeoutMs: this.timeouts.chatMs,
    });
    return chatResponseSchema.parse(response.data);
  }

  async ingestDocument(documentId: number | string, request: IngestRequest, requestId?: string): Promise<IngestResult> {
    const response = await internalFetch(this.target, {
      method: 'POST',
      path: `/v1/documents/${encodeURIComponent(String(documentId))}/ingest`,
      body: request,
      requestId,
      timeoutMs: this.timeouts.ingestMs,
    });
    const parsed = ingestResponseSchema.safeParse(response.data);
    return parsed.success ? parsed.data : null;
  }

  async deleteDocument(documentId: number | string, workspaceId: number, requestId?: string): Promise<void> {
    try {
      await internalFetch(this.target, {
        method: 'DELETE',
        path: `/v1/documents/${encodeURIComponent(String(documentId))}`,
        query: { workspace_id: workspaceId },
        requestId,
      });
    } catch (error) {
      if (isNotFound(error)) return;
      throw error;
    }
  }

  async reembedWorkspace(workspaceId: number, aiExternalAllowed: boolean, requestId?: string): Promise<void> {
    await internalFetch(this.target, {
      method: 'POST',
      path: `/v1/workspaces/${encodeURIComponent(String(workspaceId))}/reembed`,
      body: { ai_external_allowed: aiExternalAllowed },
      requestId,
      timeoutMs: this.timeouts.ingestMs,
    });
  }

  async purgeWorkspace(workspaceId: number, requestId?: string): Promise<void> {
    try {
      await internalFetch(this.target, {
        method: 'DELETE',
        path: `/v1/workspaces/${encodeURIComponent(String(workspaceId))}`,
        requestId,
        timeoutMs: this.timeouts.ingestMs,
      });
    } catch (error) {
      if (isNotFound(error)) return;
      throw error;
    }
  }

  async purgeUser(userId: number, requestId?: string): Promise<void> {
    try {
      await internalFetch(this.target, {
        method: 'DELETE',
        path: `/v1/users/${encodeURIComponent(String(userId))}`,
        requestId,
      });
    } catch (error) {
      if (isNotFound(error)) return;
      throw error;
    }
  }

  async pruneMemory(retention: MemoryRetention, requestId?: string): Promise<void> {
    await internalFetch(this.target, {
      method: 'POST',
      path: '/v1/privacy/prune-memory',
      body: retention,
      requestId,
      timeoutMs: this.timeouts.ingestMs,
    });
  }
}
