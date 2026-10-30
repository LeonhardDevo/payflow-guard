import type { FastifyPluginAsync } from 'fastify';
import type { Metrics } from '../../metrics.js';

export type ReadinessCheck = () => Promise<void>;

export interface HealthRouteDeps {
  productSlug: string;
  metrics: Metrics;
  checks: Record<string, ReadinessCheck>;
}

const startedAt = Date.now();

export function healthRoutes(deps: HealthRouteDeps): FastifyPluginAsync {
  return async (app) => {
    app.get('/healthz', async () => ({
      status: 'ok',
      service: 'worker',
      product: deps.productSlug,
      uptime_s: Math.round((Date.now() - startedAt) / 1000),
    }));

    app.get('/readyz', async (_request, reply) => {
      const results: Record<string, string> = {};
      await Promise.all(
        Object.entries(deps.checks).map(async ([name, check]) => {
          try {
            await withTimeout(check(), 2000);
            results[name] = 'ok';
          } catch (error) {
            results[name] = error instanceof Error ? error.message : 'failed';
          }
        }),
      );
      const ok = Object.values(results).every((value) => value === 'ok');
      return reply.code(ok ? 200 : 503).send({ status: ok ? 'ok' : 'degraded', checks: results });
    });

    app.get('/metrics', async (_request, reply) => {
      const { contentType, body } = await deps.metrics.render();
      return reply.header('content-type', contentType).send(body);
    });
  };
}

async function withTimeout<T>(promise: Promise<T>, ms: number): Promise<T> {
  let timer: NodeJS.Timeout | undefined;
  try {
    return await Promise.race([
      promise,
      new Promise<never>((_resolve, reject) => {
        timer = setTimeout(() => reject(new Error('timeout')), ms);
      }),
    ]);
  } finally {
    if (timer) clearTimeout(timer);
  }
}
