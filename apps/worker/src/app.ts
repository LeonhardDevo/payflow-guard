import { setTimeout as sleep } from 'node:timers/promises';
import type { Worker } from 'bullmq';
import type { FastifyInstance } from 'fastify';
import type { Redis } from 'ioredis';
import type pg from 'pg';
import { AccountResolver } from './bot/accounts.js';
import { createBot, type BotHandle } from './bot/createBot.js';
import { PayflowWebClient } from './domain/payflow/api.js';
import { PayflowJobs } from './domain/payflow/jobs/handlers.js';
import { DevJobRunner, JobActivity, registerPayflowSchedulers } from './domain/payflow/jobs/runner.js';
import { PayflowMetrics } from './domain/payflow/metrics.js';
import { defaultModules } from './bot/modules/index.js';
import { createRegistry } from './bot/registry.js';
import { TelegramRuntime } from './bot/runtime.js';
import { RedisActionStore, RedisCache, RedisRateLimiter } from './bot/stores.js';
import type { BotServices } from './bot/types.js';
import { AiClient } from './clients/ai.js';
import { WebClient } from './clients/web.js';
import type { AppConfig } from './config.js';
import { migrate } from './db/migrate.js';
import { createPool } from './db/pool.js';
import { PgChatPreferences } from './db/telegramChats.js';
import { PgWebhookEventStore } from './db/webhookEvents.js';
import { buildRagServer, buildServer } from './http/server.js';
import { Translator } from './i18n/index.js';
import { createHandlers, type JobDeps, type TelegramSender } from './jobs/index.js';
import { errorFields, type Logger } from './logger.js';
import { SmtpMailer } from './mail/mailer.js';
import { Metrics } from './metrics.js';
import { LocalAiApi } from './rag/localApi.js';
import { RagService } from './rag/service.js';
import { OutboxRelay } from './outbox/relay.js';
import { QueueRegistry } from './queues/enqueuer.js';
import { QUEUES, type QueueName } from './queues/names.js';
import { scheduleBilling, schedulePrivacyPurge } from './queues/scheduler.js';
import { createRedis, redisOptionsFromUrl } from './queues/redis.js';
import { closeWorkers, startWorkers } from './queues/workers.js';

const NOTIFICATION_ACTION_TTL_S = 7 * 24 * 3600;

export interface WorkerApp {
  readonly config: AppConfig;
  readonly server: FastifyInstance;
  readonly bot: BotHandle | null;
  readonly rag: RagService | null;
  readonly metrics: Metrics;
  start(): Promise<void>;
  stop(): Promise<void>;
}

export async function createApp(config: AppConfig, logger: Logger): Promise<WorkerApp> {
  const metrics = new Metrics();
  const pool: pg.Pool = createPool(config.database.url, config.database.poolMax, logger);
  const rag = config.roles.has('rag') ? createRag(config, logger, pool, metrics) : null;
  if (rag && !hasWorkerRole(config)) return createRagApp(config, logger, pool, metrics, rag);
  const redis: Redis = createRedis(config.redis.url, `${config.product.slug}-worker`);
  redis.on('error', (error) => logger.warn(errorFields(error), 'redis connection error'));
  const connection = redisOptionsFromUrl(config.redis.url);
  const queues = new QueueRegistry(connection, config.redis.queuePrefix);
  metrics.setQueueDepthSource(async () =>
    Promise.all(
      [...queues.queues.values()].map(async (queue) => ({
        queue: queue.name,
        counts: await queue.getJobCounts('waiting', 'active', 'delayed', 'failed', 'completed', 'prioritized', 'paused'),
      })),
    ),
  );

  const web = new WebClient({ service: 'web', baseUrl: config.internal.webUrl, secret: config.internal.keys.web, timeoutMs: config.timeouts.webMs });
  const ai = rag
    ? new LocalAiApi(rag)
    : new AiClient(
        { service: 'ai', baseUrl: config.internal.aiUrl, secret: config.internal.keys.ai, timeoutMs: config.timeouts.webMs },
        { chatMs: config.timeouts.aiChatMs, ingestMs: config.timeouts.aiIngestMs },
      );

  const payflowApi = new PayflowWebClient({ service: 'web', baseUrl: config.internal.webUrl, secret: config.internal.keys.web, timeoutMs: config.timeouts.webMs });
  const payflowMetrics = new PayflowMetrics(metrics.registry);
  const translator = new Translator(config.product.name);
  const cache = new RedisCache(redis, config.redis.keyPrefix);
  const registry = createRegistry(defaultModules({ payflow: { api: payflowApi, cache } }), translator);
  const actions = new RedisActionStore(redis, config.redis.keyPrefix);
  const chatPreferences = new PgChatPreferences(pool);
  const services: BotServices = {
    settings: {
      productName: config.product.name,
      botUsername: config.telegram.botUsername,
      webPublicUrl: config.internal.webPublicUrl,
      rateLimit: config.telegram.rateLimit,
      rateWindowS: config.telegram.rateWindowS,
      actionTtlS: config.telegram.actionTtlS,
      logAiQueries: config.logAiQueries,
    },
    translator,
    web,
    ai,
    actions,
    rateLimiter: new RedisRateLimiter(redis, config.redis.keyPrefix),
    preferences: chatPreferences,
    accounts: new AccountResolver(web, cache, config.telegram.accountCacheTtlS),
    logger: logger.child({ component: 'bot' }),
    metrics,
    registry,
  };
  const bot = createBot(services, {
    token: config.telegram.token,
    apiRoot: config.telegram.apiRoot,
    botUsername: config.telegram.botUsername,
    productName: config.product.name,
  });

  const webhookEvents = new PgWebhookEventStore(pool);
  const mailer = new SmtpMailer(config.smtp);
  const telegram: TelegramSender = {
    sendMessage: (chatId, html, replyMarkup) =>
      bot.bot.api.sendMessage(chatId, html, {
        parse_mode: 'HTML',
        link_preview_options: { is_disabled: true },
        ...(replyMarkup ? { reply_markup: replyMarkup } : {}),
      }),
  };
  const jobDeps: JobDeps = {
    web,
    ai,
    mailer,
    telegram,
    sentMarkers: cache,
    webhookEvents,
    telegramChats: chatPreferences,
    bot,
    registry,
    translator,
    actions,
    settings: {
      productSlug: config.product.slug,
      productName: config.product.name,
      mailFrom: config.smtp.from,
      notificationActionTtlS: NOTIFICATION_ACTION_TTL_S,
    },
    hooks: { emailDeferred: () => payflowMetrics.clientMailDeferred.inc() },
  };
  const payflowJobs = new PayflowJobs({ api: payflowApi, enqueuer: queues, metrics: payflowMetrics });
  const handlers = createHandlers(jobDeps, payflowJobs.handlers());
  const activity = new JobActivity();
  const devJobs =
    config.devRoutes && config.roles.has('jobs')
      ? new DevJobRunner(payflowJobs, (name) => queues.queues.get(name as QueueName) ?? null, activity, logger.child({ component: 'dev-jobs' }))
      : null;

  const relay = new OutboxRelay({
    pool,
    enqueuer: queues,
    logger: logger.child({ component: 'outbox' }),
    metrics,
    table: config.outbox.table,
    channel: config.outbox.channel,
    pollMs: config.outbox.pollMs,
    batchSize: config.outbox.batchSize,
    listenConnectionString: config.database.url,
  });
  const runtime = new TelegramRuntime(bot, config.telegram, queues, registry, translator, logger.child({ component: 'telegram' }));

  const server = await buildServer({
    logger,
    metrics,
    enqueuer: queues,
    webhookEvents,
    product: config.product,
    stripeWebhookSecret: config.stripe.webhookSecret,
    yookassaWebhook: { allowedIps: config.webhooks.yookassaAllowedIps, trustedProxyHops: config.webhooks.trustedProxyHops },
    paymentAccountSecrets: (accountId) => web.paymentAccountSecret(accountId),
    paymentEndpoints: (endpointKey) => web.paymentEndpoint(endpointKey),
    telegramWebhook: { enabled: config.telegram.mode === 'webhook', secret: config.telegram.webhookSecret },
    devBot: config.devRoutes && config.roles.has('ingress') ? bot : null,
    devJobs,
    rag: rag ? { service: rag, peerSecrets: ragPeerSecrets(config) } : null,
    ingress: config.roles.has('ingress'),
    dashboard: config.dashboard ? { queues: [...queues.queues.values()], ...config.dashboard } : null,
    readiness: {
      postgres: async () => {
        await pool.query('SELECT 1');
      },
      redis: async () => {
        await redis.ping();
      },
    },
  });
  if (!config.dashboard) logger.warn('QUEUE_DASHBOARD_USER/PASSWORD not set; /admin/queues is disabled');

  let workers: Worker[] = [];

  return {
    config,
    server,
    bot,
    rag,
    metrics,
    async start() {
      await retry(() => migrate(pool, logger), logger, 'postgres migrations');
      if (rag) {
        await retry(() => rag.migrate(), logger, 'rag migrations');
        rag.warmUp();
      }
      const jobs = config.roles.has('jobs');
      if (jobs && config.workersEnabled) {
        workers = startWorkers(handlers, {
          connection,
          prefix: config.redis.queuePrefix,
          logger,
          metrics,
          onSettled: (queue, outcome) => activity.record(queue, outcome),
        });
        await registerPayflowSchedulers(queues.get(QUEUES.pfScheduler), logger).catch((error: unknown) =>
          logger.error(errorFields(error), 'payflow scheduler registration failed'),
        );
        await schedulePrivacyPurge(queues.get(QUEUES.privacy), config.privacyPurgeCron, logger).catch((error: unknown) =>
          logger.error(errorFields(error), 'privacy purge scheduler registration failed'),
        );
        await scheduleBilling(
          queues.get(QUEUES.billing),
          { renewals: config.billingRenewalsCron, reconcile: config.paymentsReconcileCron },
          logger,
        ).catch((error: unknown) => logger.error(errorFields(error), 'billing scheduler registration failed'));
      }
      if (jobs && config.outbox.enabled) await relay.start();
      if (config.telegram.mode === 'disabled' || !config.roles.has('bot')) await bot.init(logger);
      else await runtime.start();
      await server.listen({ host: config.host, port: config.port });
      logger.info(
        {
          port: config.port,
          telegram: runtime.mode,
          devRoutes: config.devRoutes,
          dashboard: Boolean(config.dashboard),
          product: config.product.slug,
          roles: [...config.roles],
        },
        'worker ready',
      );
    },
    async stop() {
      await server.close().catch(() => undefined);
      await rag?.flush().catch(() => undefined);
      await runtime.stop();
      await relay.stop();
      await closeWorkers(workers);
      await queues.close();
      mailer.close();
      await redis.quit().catch(() => undefined);
      await pool.end().catch(() => undefined);
    },
  };
}

function hasWorkerRole(config: AppConfig): boolean {
  return config.roles.has('jobs') || config.roles.has('bot') || config.roles.has('ingress');
}

function ragPeerSecrets(config: AppConfig): { web: string; worker: string } {
  return { web: config.internal.keys.webAi, worker: config.internal.keys.ai };
}

function createRag(config: AppConfig, logger: Logger, pool: pg.Pool, metrics: Metrics): RagService {
  return new RagService({
    config: config.rag,
    productName: config.product.name,
    publicWebUrl: config.internal.webPublicUrl,
    webUrl: config.internal.webUrl,
    webAiKey: config.internal.keys.webAi,
    sharedSecret: config.internal.secret,
    isProduction: config.isProduction,
    webTimeoutMs: config.timeouts.webMs,
    pool,
    logger: logger.child({ component: 'rag' }),
    metricsRegistry: metrics.registry,
  });
}

async function createRagApp(config: AppConfig, logger: Logger, pool: pg.Pool, metrics: Metrics, rag: RagService): Promise<WorkerApp> {
  const server = await buildRagServer({
    logger,
    metrics,
    product: config.product,
    rag: { service: rag, peerSecrets: ragPeerSecrets(config) },
    readiness: {
      postgres: async () => {
        await pool.query('SELECT 1');
      },
    },
  });
  return {
    config,
    server,
    bot: null,
    rag,
    metrics,
    async start() {
      await retry(() => rag.migrate(), logger, 'rag migrations');
      rag.warmUp();
      await server.listen({ host: config.host, port: config.port });
      logger.info({ port: config.port, product: config.product.slug, roles: [...config.roles] }, 'rag service ready');
    },
    async stop() {
      await server.close().catch(() => undefined);
      await rag.flush().catch(() => undefined);
      await pool.end().catch(() => undefined);
    },
  };
}

async function retry<T>(fn: () => Promise<T>, logger: Logger, what: string, attempts = 15, delayMs = 2000): Promise<T> {
  for (let attempt = 1; ; attempt++) {
    try {
      return await fn();
    } catch (error) {
      if (attempt >= attempts) throw error;
      logger.warn({ attempt, ...errorFields(error) }, `${what} failed; retrying`);
      await sleep(delayMs);
    }
  }
}
