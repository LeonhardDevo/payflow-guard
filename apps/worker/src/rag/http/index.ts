import type { FastifyPluginAsync } from 'fastify';
import { reminderDraftRoutes } from '../domain/payflow/http.js';
import { ragRoutes, type RagDomainRoutes, type RagRouteDeps } from './routes.js';

export const DOMAIN_ROUTES: readonly RagDomainRoutes[] = [reminderDraftRoutes];

export function ragHttp(deps: Omit<RagRouteDeps, 'domainRoutes'>): FastifyPluginAsync {
  return ragRoutes({ ...deps, domainRoutes: DOMAIN_ROUTES });
}

export { body, callerOf, requestIdOf, validate, type RagDomainRoutes, type RagRouteDeps } from './routes.js';
