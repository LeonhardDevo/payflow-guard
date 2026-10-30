import type { Logger } from '../../logger.js';
import type { AiManager } from '../providers/manager.js';
import type { AiProvider, ChatMessage, ToolCall } from '../providers/types.js';
import { toolDefinition, type ChatAction, type Tool, type ToolContext, type ToolResult } from '../tools/types.js';
import { ToolError } from '../tools/types.js';
import { t } from './i18n.js';

export const MAX_TOOL_RESULT_CHARS = 12_000;

export interface LoopOutcome {
  text: string | null;
  finishReason: string;
  provider: string;
  model: string;
  iterations: number;
  toolsUsed: string[];
  toolData: boolean;
  toolConfidence: number;
  actions: ChatAction[];
}

export interface LoopRunOptions {
  metadata: Record<string, unknown>;
  provider?: AiProvider;
  allowExternal?: boolean;
}

export class AgentLoop {
  constructor(
    private readonly manager: AiManager,
    private readonly maxIterations: number,
    private readonly logger: Logger,
  ) {}

  async run(messages: ChatMessage[], tools: readonly Tool[], ctx: ToolContext, options: LoopRunOptions): Promise<LoopOutcome> {
    const definitions = tools.map(toolDefinition);
    const byName = new Map(tools.map((tool) => [tool.name, tool]));
    const outcome: LoopOutcome = {
      text: null,
      finishReason: 'stop',
      provider: '',
      model: '',
      iterations: 0,
      toolsUsed: [],
      toolData: false,
      toolConfidence: 0,
      actions: [],
    };
    const seenCalls = new Map<string, string>();

    for (;;) {
      const budgetLeft = outcome.iterations < this.maxIterations;
      const managed = await this.manager.chat(
        { model: '', messages, tools: definitions, metadata: options.metadata },
        {
          purpose: 'chat',
          workspaceId: ctx.workspaceId,
          requestId: ctx.requestId,
          ...(options.provider ? { provider: options.provider } : {}),
          ...(options.allowExternal !== undefined ? { allowExternal: options.allowExternal } : {}),
        },
      );
      const result = managed.result;
      outcome.provider = managed.provider;
      outcome.model = managed.model;
      outcome.text = result.content;
      outcome.finishReason = result.finishReason;
      if (result.toolCalls.length === 0 || !budgetLeft) return outcome;

      outcome.iterations += 1;
      messages.push({
        role: 'assistant',
        content: result.content ?? '',
        toolCalls: [...result.toolCalls],
        providerBlocks: result.providerBlocks ?? null,
        providerId: managed.provider,
      });
      for (const call of result.toolCalls) {
        const content = await this.execute(call, byName, ctx, outcome, seenCalls);
        messages.push({ role: 'tool', content, toolCallId: call.id });
      }
      if (outcome.iterations >= this.maxIterations) messages.push({ role: 'user', content: t('budget_exhausted', ctx.locale) });
    }
  }

  private async execute(
    call: ToolCall,
    byName: Map<string, Tool>,
    ctx: ToolContext,
    outcome: LoopOutcome,
    seenCalls: Map<string, string>,
  ): Promise<string> {
    const signature = `${call.name}:${call.arguments}`;
    const previous = seenCalls.get(signature);
    if (previous !== undefined) return `Duplicate call: the result is the same as before.\n${previous}`;
    const tool = byName.get(call.name);
    if (!tool) return `Error: unknown tool '${call.name}'.`;
    if (!ctx.user.can(tool.requiredPermission)) return `Error: the user is not allowed to use '${call.name}'.`;
    let args: Record<string, unknown>;
    try {
      const parsed: unknown = JSON.parse(call.arguments || '{}');
      if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) throw new Error('arguments must be a JSON object');
      args = parsed as Record<string, unknown>;
    } catch (error) {
      return `Error: invalid arguments for '${call.name}': ${error instanceof Error ? error.message : String(error)}`;
    }
    let result: ToolResult;
    try {
      result = await tool.run(ctx, args);
    } catch (error) {
      if (error instanceof ToolError) return `Error: ${error.message}`;
      this.logger.warn({ tool: call.name, error: error instanceof Error ? error.message : String(error) }, 'tool failed');
      return `Error: '${call.name}' failed; continue without it.`;
    }
    if (!outcome.toolsUsed.includes(call.name)) outcome.toolsUsed.push(call.name);
    outcome.toolData = outcome.toolData || Boolean(result.hasData);
    if (result.confidence !== undefined && result.confidence !== null) outcome.toolConfidence = Math.max(outcome.toolConfidence, result.confidence);
    outcome.actions.push(...(result.actions ?? []));
    const content = result.content.slice(0, MAX_TOOL_RESULT_CHARS);
    seenCalls.set(signature, content);
    return content;
  }
}
