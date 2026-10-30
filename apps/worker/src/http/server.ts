import { randomUUID } from 'node:crypto';
import type { Queue } from 'bullmq';
import Fastify, { LogController, type FastifyBaseLogger, type FastifyInstance } from 'fastify';
import type { BotHandle } from '../bot/createBot.js';
import type { ServiceName } from '../clients/signing.js';
import type { WebhookEventStore } from '../db/webhookEvents.js';
import { devJobRoutes } from '../domain/payflow/jobs/devRoutes.js';
import type { DevJobRunner } from '../domain/payflow/jobs/runner.js';
import { errorFields, type Logger } from '../logger.js';
import type { Metrics } from '../metrics.js';
import type { JobEnqueuer } from '../queues/enqueuer.js';
import { ragHttp } from '../rag/http/index.js';
import type { RagService } from '../rag/service.js';
import { devRoutes } from './routes/dev.js';
import { healthRoutes, type ReadinessCheck } from './routes/health.js';
import { queueDashboardRoutes } from './routes/queueDashboard.js';
import { paymentEndpointWebhookRoutes, type PaymentEndpointLookup } from './routes/paymentEndpoints.js';
import { paymentWebhookRoutes, type PaymentAccountSecretLookup } from './routes/payments.js';
import { stripeWebhookRoutes } from './routes/stripe.js';
import { telegramWebhookRoutes } from './routes/telegram.js';
import { yookassaWebhookRoutes } from './routes/yookassa.js';

export interface HttpServerDeps {
  logger: Logger;
  metrics: Metrics;
  enqueuer: JobEnqueuer;
  webhookEvents: WebhookEventStore;
  product: { name: string; slug: string };
  stripeWebhookSecret: string;
  yookassaWebhook?: { allowedIps: readonly string[]; trustedProxyHops: number };
  paymentAccountSecrets?: PaymentAccountSecretLookup;
  paymentEndpoints?: PaymentEndpointLookup;
  telegramWebhook: { enabled: boolean; secret: string | undefined };
  devBot: BotHandle | null;
  devJobs?: DevJobRunner | null;
  dashboard: { queues: Queue[]; user: string; password: string } | null;
  readiness: Record<string, ReadinessCheck>;
  rag?: RagHttpDeps | null;
  ingress?: boolean;
}

export interface RagHttpDeps {
  service: RagService;
  peerSecrets: Partial<Record<ServiceName, string>>;
}

export interface RagServerDeps {
  logger: Logger;
  metrics: Metrics;
  product: { name: string; slug: string };
  readiness: Record<string, ReadinessCheck>;
  rag: RagHttpDeps;
}

const QUIET_ROUTES = new Set(['/healthz', '/readyz', '/metrics']);

function createFastify(baseLogger: Logger): FastifyInstance {
  const logger: FastifyBaseLogger = baseLogger.child({ component: 'http' });
  const app = Fastify({
    loggerInstance: logger,
    logController: new LogController({ disableRequestLogging: true }),
    requestIdHeader: 'x-request-id',
    genReqId: () => randomUUID(),
    trustProxy: true,
    bodyLimit: 1024 * 1024,
  });

  app.addHook('onSend', async (request, reply, payload) => {
    reply.header('x-request-id', request.id);
    return payload;
  });
  app.addHook('onResponse', async (request, reply) => {
    const path = request.url.split('?')[0] ?? request.url;
    const fields = { method: request.method, path, status: reply.statusCode, ms: Math.round(reply.elapsedTime) };
    if (QUIET_ROUTES.has(path)) request.log.debug(fields, 'request');
    else request.log.info(fields, 'request');
  });
  app.setErrorHandler((error: Error & { statusCode?: number }, request, reply) => {
    const status = error.statusCode && error.statusCode >= 400 ? error.statusCode : 500;
    if (status >= 500) request.log.error(errorFields(error), 'request failed');
    return reply.code(status).send({ error: status >= 500 ? 'internal_error' : error.message });
  });
  app.setNotFoundHandler((_request, reply) => reply.code(404).send({ error: 'not_found' }));
  return app;
}

export async function buildRagServer(deps: RagServerDeps): Promise<FastifyInstance> {
  const app = createFastify(deps.logger);
  await app.register(healthRoutes({ productSlug: deps.product.slug, metrics: deps.metrics, checks: deps.readiness }));
  await app.register(ragHttp({ rag: deps.rag.service, peerSecrets: deps.rag.peerSecrets }));
  return app;
}

export async function buildServer(deps: HttpServerDeps): Promise<FastifyInstance> {
  const app = createFastify(deps.logger);
  await app.register(healthRoutes({ productSlug: deps.product.slug, metrics: deps.metrics, checks: deps.readiness }));
  if (deps.rag) await app.register(ragHttp({ rag: deps.rag.service, peerSecrets: deps.rag.peerSecrets }));
  if (deps.ingress === false) return app;
  await app.register(
    stripeWebhookRoutes({
      webhookSecret: deps.stripeWebhookSecret,
      webhookEvents: deps.webhookEvents,
      enqueuer: deps.enqueuer,
      metrics: deps.metrics,
    }),
  );
  if (deps.yookassaWebhook) {
    await app.register(
      yookassaWebhookRoutes({
        ...deps.yookassaWebhook,
        webhookEvents: deps.webhookEvents,
        enqueuer: deps.enqueuer,
        metrics: deps.metrics,
      }),
    );
  }
  if (deps.paymentAccountSecrets) {
    await app.register(
      paymentWebhookRoutes({
        secretFor: deps.paymentAccountSecrets,
        webhookEvents: deps.webhookEvents,
        enqueuer: deps.enqueuer,
        metrics: deps.metrics,
      }),
    );
  }
  if (deps.paymentEndpoints) {
    await app.register(
      paymentEndpointWebhookRoutes({
        lookup: deps.paymentEndpoints,
        webhookEvents: deps.webhookEvents,
        enqueuer: deps.enqueuer,
        metrics: deps.metrics,
      }),
    );
  }
  await app.register(
    telegramWebhookRoutes({
      enabled: deps.telegramWebhook.enabled,
      secret: deps.telegramWebhook.secret,
      enqueuer: deps.enqueuer,
      metrics: deps.metrics,
    }),
  );
  if (deps.devBot) {
    await app.register(devRoutes({ bot: deps.devBot }));
  }
  if (deps.devJobs) {
    await app.register(devJobRoutes(deps.devJobs));
  }
  if (deps.dashboard) {
    await app.register(
      queueDashboardRoutes({
        ...deps.dashboard,
        productName: deps.product.name,
        productSlug: deps.product.slug,
      }),
    );
  }
  return app;
}
