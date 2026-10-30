import type { Logger } from '../../logger.js';
import type { RagLocale } from '../agent/i18n.js';
import type { Source, SourcePool } from '../agent/sources.js';
import type { Embedder } from '../embeddings/types.js';
import type { ToolDefinition } from '../providers/types.js';
import type { HybridRetriever, Viewer } from '../retrieval/hybrid.js';
import type { RagWebApi } from '../web/client.js';

export interface ChatAction {
  type: string;
  label: string;
  payload: Record<string, unknown>;
}

export class ChatUser {
  readonly permissions: ReadonlySet<string>;

  constructor(
    readonly id: number | null,
    readonly role: string,
    readonly locale: RagLocale,
    permissions: Iterable<string>,
  ) {
    this.permissions = new Set(permissions);
  }

  can(permission: string | null): boolean {
    return permission === null || this.permissions.has(permission) || this.permissions.has('*');
  }
}

export interface ToolContext {
  workspaceId: number;
  user: ChatUser;
  viewer: Viewer;
  retriever: HybridRetriever;
  embedder: Embedder;
  web: RagWebApi;
  sources: SourcePool;
  topK: number;
  publicWebUrl: string;
  requestId: string | null;
  locale: RagLocale;
  logger: Logger;
}

export interface ToolResult {
  content: string;
  sources?: Source[];
  hasData?: boolean;
  actions?: ChatAction[];
  confidence?: number | null;
}

export class ToolError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'ToolError';
  }
}

export interface Tool {
  readonly name: string;
  readonly description: string;
  readonly parameters: Record<string, unknown>;
  readonly requiredPermission: string | null;
  readonly dataTool: boolean;
  run(ctx: ToolContext, args: Record<string, unknown>): Promise<ToolResult>;
}

export function toolDefinition(tool: Tool): ToolDefinition {
  return { name: tool.name, description: tool.description, parameters: tool.parameters };
}

export class ToolRegistry {
  private readonly tools = new Map<string, Tool>();

  register(tool: Tool): void {
    if (this.tools.has(tool.name)) throw new Error(`tool '${tool.name}' is already registered`);
    this.tools.set(tool.name, tool);
  }

  get(name: string): Tool | undefined {
    return this.tools.get(name);
  }

  all(): Tool[] {
    return [...this.tools.values()];
  }

  availableFor(user: ChatUser): Tool[] {
    return this.all().filter((tool) => user.can(tool.requiredPermission));
  }
}
