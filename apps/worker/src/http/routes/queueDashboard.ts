import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { createBullBoard } from '@bull-board/api';
import { BullMQAdapter } from '@bull-board/api/bullMQAdapter';
import { FastifyAdapter } from '@bull-board/fastify';
import type { Queue } from 'bullmq';
import type { FastifyPluginAsync } from 'fastify';
import { safeEqual } from '../../clients/signing.js';

export const DASHBOARD_PATH = '/admin/queues';

const CSP = [
  "default-src 'self'",
  "script-src 'self'",
  "style-src 'self' 'unsafe-inline'",
  "img-src 'self' data:",
  "font-src 'self' data:",
  "connect-src 'self'",
  "frame-ancestors 'none'",
  "base-uri 'self'",
  "form-action 'self'",
].join('; ');

export function prepareOfflineViews(productSlug: string): string {
  const localRequire = createRequire(import.meta.url);
  const apiRequire = createRequire(localRequire.resolve('@bull-board/api'));
  const uiPackage = apiRequire.resolve('@bull-board/ui/package.json');
  const source = join(dirname(uiPackage), 'dist', 'index.ejs');
  const template = readFileSync(source, 'utf8').replace(/<link[^>]+href="https?:\/\/[^"]*"[^>]*>\s*/g, '');
  const target = join(tmpdir(), `${productSlug}-worker-bull-board`);
  mkdirSync(target, { recursive: true });
  writeFileSync(join(target, 'index.ejs'), template, 'utf8');
  return target;
}

export function queueDashboardRoutes(deps: {
  queues: Queue[];
  user: string;
  password: string;
  productName: string;
  productSlug: string;
}): FastifyPluginAsync {
  return async (app) => {
    const serverAdapter = new FastifyAdapter();
    createBullBoard({
      queues: deps.queues.map((queue) => new BullMQAdapter(queue)),
      serverAdapter,
      options: { uiConfig: { boardTitle: `${deps.productName} queues` } },
    });
    serverAdapter.setBasePath(DASHBOARD_PATH);
    serverAdapter.setViewsPath(prepareOfflineViews(deps.productSlug));

    app.addHook('onRequest', async (request, reply) => {
      if (!isAuthorized(request.headers.authorization, deps.user, deps.password)) {
        return reply
          .code(401)
          .header('WWW-Authenticate', 'Basic realm="queues", charset="UTF-8"')
          .send({ error: 'unauthorized' });
      }
    });
    app.addHook('onSend', async (_request, reply, payload) => {
      reply.header('Content-Security-Policy', CSP);
      reply.header('X-Frame-Options', 'DENY');
      reply.header('Referrer-Policy', 'no-referrer');
      reply.header('X-Content-Type-Options', 'nosniff');
      return payload;
    });
    await app.register(serverAdapter.registerPlugin(), { prefix: DASHBOARD_PATH });
  };
}

export function isAuthorized(header: string | undefined, user: string, password: string): boolean {
  if (!header?.startsWith('Basic ')) return false;
  const decoded = Buffer.from(header.slice(6).trim(), 'base64').toString('utf8');
  const separator = decoded.indexOf(':');
  if (separator === -1) return false;
  const givenUser = decoded.slice(0, separator);
  const givenPassword = decoded.slice(separator + 1);
  const userOk = safeEqual(givenUser, user);
  const passwordOk = safeEqual(givenPassword, password);
  return userOk && passwordOk;
}
