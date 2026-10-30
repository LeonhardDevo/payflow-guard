import { UnrecoverableError } from 'bullmq';
import {
  chatResponseSchema,
  type AiApi,
  type ChatRequest,
  type ChatResponse,
  type IngestRequest,
  type IngestResult,
  type MemoryRetention,
} from '../clients/ai.js';
import { chatBodySchema, ingestBodySchema, memoryPruneBodySchema, validationPayload } from './http/schemas.js';
import type { RagService } from './service.js';

function numericId(value: number | string, what: string): number {
  const id = typeof value === 'number' ? value : /^\d+$/.test(value) ? Number(value) : Number.NaN;
  if (!Number.isSafeInteger(id) || id <= 0) throw new UnrecoverableError(`${what} id must be a positive integer, got ${String(value)}`);
  return id;
}

export class LocalAiApi implements AiApi {
  constructor(private readonly rag: RagService) {}

  async chat(request: ChatRequest, requestId?: string): Promise<ChatResponse> {
    const parsed = chatBodySchema.safeParse(request);
    if (!parsed.success) throw new UnrecoverableError(`invalid chat request: ${validationPayload(parsed.error).detail}`);
    return chatResponseSchema.parse(await this.rag.chat(parsed.data, requestId));
  }

  async ingestDocument(documentId: number | string, request: IngestRequest, requestId?: string): Promise<IngestResult> {
    const id = numericId(documentId, 'document');
    const parsed = ingestBodySchema.safeParse({
      ...request,
      title: request.title && request.title.trim() !== '' ? request.title : `Document ${id}`,
    });
    if (!parsed.success) throw new UnrecoverableError(`invalid ingest request: ${validationPayload(parsed.error).detail}`);
    const outcome = await this.rag.ingest(id, parsed.data, requestId);
    return { pages: outcome.pages, chunks: outcome.chunks, ocr_used: outcome.ocr_used };
  }

  async deleteDocument(documentId: number | string, workspaceId: number): Promise<void> {
    await this.rag.deleteDocument(numericId(documentId, 'document'), numericId(workspaceId, 'workspace'));
  }

  async reembedWorkspace(workspaceId: number, aiExternalAllowed: boolean): Promise<void> {
    await this.rag.reembedWorkspace(numericId(workspaceId, 'workspace'), aiExternalAllowed);
  }

  async purgeWorkspace(workspaceId: number): Promise<void> {
    await this.rag.deleteWorkspace(numericId(workspaceId, 'workspace'));
  }

  async purgeUser(userId: number): Promise<void> {
    await this.rag.deleteUser(numericId(userId, 'user'));
  }

  async pruneMemory(retention: MemoryRetention): Promise<void> {
    const parsed = memoryPruneBodySchema.safeParse(retention);
    if (!parsed.success) throw new UnrecoverableError(`invalid memory retention: ${validationPayload(parsed.error).detail}`);
    await this.rag.pruneMemory(parsed.data.default_days, parsed.data.workspaces);
  }
}
