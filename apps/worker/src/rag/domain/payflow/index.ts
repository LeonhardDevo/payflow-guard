import { fileURLToPath } from 'node:url';
import type { InputGuard } from '../../agent/pipeline.js';
import type { ToolRegistry } from '../../tools/types.js';
import { createPayflowInputGuard, payflowPlanner } from './planner.js';
import { registerPayflowTools } from './tools/payflowTool.js';

export { INTENT_RULES, matchIntent, PAYFLOW_INTENTS } from './intents.js';
export { payflowPlanner } from './planner.js';
export { reminderDraftRoutes } from './http.js';

export const PAYFLOW_PROMPTS_DIR = fileURLToPath(new URL('./prompts/', import.meta.url));

export function register(registry: ToolRegistry): void {
  registerPayflowTools(registry);
}

export const PLANNERS = [payflowPlanner] as const;

export function inputGuards(settings: { publicWebUrl: string }): InputGuard[] {
  return [createPayflowInputGuard(settings.publicWebUrl)];
}
