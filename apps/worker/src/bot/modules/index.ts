import { createPayflowModule, type PayflowModuleDeps } from '../../domain/payflow/bot/module.js';
import type { BotModule } from '../types.js';
import { assistantModule } from './assistant.js';
import { coreModule } from './core.js';
import { privacyModule } from './privacy.js';

export interface DomainModuleDeps {
  payflow: PayflowModuleDeps;
}

export function domainModules(deps: DomainModuleDeps): BotModule[] {
  return [createPayflowModule(deps.payflow)];
}

export function defaultModules(deps?: DomainModuleDeps): BotModule[] {
  return [coreModule, privacyModule, ...(deps ? domainModules(deps) : []), assistantModule];
}

export { assistantModule, coreModule, privacyModule };
