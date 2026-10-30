import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { z } from 'zod';
import { parseRagConfig, type RagConfig } from './rag/config.js';

const booleanish = z
  .union([z.boolean(), z.string()])
  .transform((value) => (typeof value === 'boolean' ? value : ['1', 'true', 'yes', 'on'].includes(value.trim().toLowerCase())));

const optionalString = z
  .string()
  .optional()
  .transform((value) => (value === undefined || value.trim() === '' ? undefined : value.trim()));

const url = z.string().trim().url();

const envSchema = z
  .object({
    NODE_ENV: z.enum(['development', 'test', 'production']).default('development'),
    HOST: z.string().trim().default('127.0.0.1'),
    PORT: z.coerce.number().int().min(1).max(65535).default(8101),
    LOG_LEVEL: z.enum(['fatal', 'error', 'warn', 'info', 'debug', 'trace', 'silent']).default('info'),
    LOG_PRETTY: booleanish.default(false),

    PRODUCT_NAME: z.string().trim().min(1),
    PRODUCT_SLUG: z
      .string()
      .trim()
      .regex(/^[a-z0-9][a-z0-9-]{0,40}$/, 'PRODUCT_SLUG must be lowercase letters, digits and dashes'),

    DATABASE_URL: url,
    DATABASE_POOL_MAX: z.coerce.number().int().min(1).max(100).default(10),
    REDIS_URL: url,
    QUEUE_PREFIX: z.string().trim().min(1).default('bull'),

    INTERNAL_API_SECRET: z.string().min(16, 'INTERNAL_API_SECRET must be at least 16 characters').optional(),
    INTERNAL_KEY_WEB_WORKER: optionalString,
    INTERNAL_KEY_WORKER_AI: optionalString,
    INTERNAL_KEY_WEB_AI: optionalString,
    WORKER_ROLES: optionalString,
    WEB_URL: url,
    WEB_PUBLIC_URL: optionalString,
    AI_URL: url.default('http://127.0.0.1:8101'),
    WEB_TIMEOUT_MS: z.coerce.number().int().positive().default(10_000),
    AI_CHAT_TIMEOUT_MS: z.coerce.number().int().positive().default(90_000),
    AI_INGEST_TIMEOUT_MS: z.coerce.number().int().positive().default(600_000),
    LOG_AI_QUERIES: booleanish.default(false),

    OUTBOX_TABLE: z
      .string()
      .trim()
      .regex(/^[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*$/, 'OUTBOX_TABLE must be schema.table')
      .default('public.outbox_messages'),
    OUTBOX_CHANNEL: z
      .string()
      .trim()
      .regex(/^[a-z_][a-z0-9_]*$/)
      .default('outbox'),
    OUTBOX_POLL_MS: z.coerce.number().int().min(100).default(2_000),
    OUTBOX_BATCH_SIZE: z.coerce.number().int().min(1).max(1000).default(100),
    OUTBOX_ENABLED: booleanish.default(true),

    STRIPE_WEBHOOK_SECRET: optionalString,
    STRIPE_SECRET_KEY: optionalString,

    TELEGRAM_MODE: z.enum(['polling', 'webhook', 'disabled']).default('polling'),
    TELEGRAM_BOT_TOKEN: optionalString,
    TELEGRAM_BOT_USERNAME: optionalString,
    TELEGRAM_WEBHOOK_SECRET: optionalString,
    TELEGRAM_WEBHOOK_URL: optionalString,
    TELEGRAM_API_ROOT: url.default('https://api.telegram.org'),
    BOT_RATE_LIMIT: z.coerce.number().int().min(1).default(20),
    BOT_RATE_WINDOW_S: z.coerce.number().int().min(1).default(60),
    BOT_ACTION_TTL_S: z.coerce.number().int().min(60).default(86_400),
    BOT_ACCOUNT_CACHE_TTL_S: z.coerce.number().int().min(0).default(60),

    SMTP_HOST: z.string().trim().default('127.0.0.1'),
    SMTP_PORT: z.coerce.number().int().min(1).max(65535).default(1025),
    SMTP_SECURE: booleanish.default(false),
    SMTP_USER: optionalString,
    SMTP_PASSWORD: optionalString,
    MAIL_FROM: optionalString,

    DEV_ROUTES: booleanish.default(false),
    QUEUE_DASHBOARD_USER: optionalString,
    QUEUE_DASHBOARD_PASSWORD: optionalString,
    WORKERS_ENABLED: booleanish.default(true),
    PRIVACY_PURGE_CRON: z.string().trim().default('15 3 * * *'),
    BILLING_RENEWALS_CRON: z.string().trim().default('20 * * * *'),
    PAYMENTS_RECONCILE_CRON: z.string().trim().default('*/30 * * * *'),
    YOOKASSA_ALLOWED_IPS: z
      .string()
      .trim()
      .default('185.71.76.0/27,185.71.77.0/27,77.75.153.0/25,77.75.156.11/32,77.75.156.35/32,77.75.154.128/25,2a02:5180::/32'),
    WEBHOOK_TRUSTED_PROXY_HOPS: z.coerce.number().int().min(0).max(5).default(0),
  })
  .superRefine((env, ctx) => {
    const roles = parseRoles(env.WORKER_ROLES, env.NODE_ENV === 'production');
    if (!roles.ok) {
      ctx.addIssue({ code: 'custom', path: ['WORKER_ROLES'], message: roles.message });
      return;
    }
    if (env.NODE_ENV === 'production') {
      if (roles.roles.has('all')) {
        ctx.addIssue({ code: 'custom', path: ['WORKER_ROLES'], message: 'must list separate roles in production (jobs,bot,ingress or rag)' });
      }
      const required = new Set<PairKey>(['INTERNAL_KEY_WORKER_AI']);
      if (hasWorkerRole(roles.roles)) required.add('INTERNAL_KEY_WEB_WORKER');
      if (roles.roles.has('rag')) required.add('INTERNAL_KEY_WEB_AI');
      for (const key of PAIR_KEYS) {
        if (required.has(key) && (env[key] ?? '').length < MIN_PRODUCTION_SECRET_LENGTH) {
          ctx.addIssue({ code: 'custom', path: [key], message: `is required (${MIN_PRODUCTION_SECRET_LENGTH}+ characters) in production` });
        }
      }
      if (env.INTERNAL_KEY_WEB_WORKER !== undefined && env.INTERNAL_KEY_WEB_WORKER === env.INTERNAL_KEY_WORKER_AI) {
        ctx.addIssue({ code: 'custom', path: ['INTERNAL_KEY_WORKER_AI'], message: 'must differ from INTERNAL_KEY_WEB_WORKER' });
      }
      if (
        env.INTERNAL_KEY_WEB_AI !== undefined &&
        (env.INTERNAL_KEY_WEB_AI === env.INTERNAL_KEY_WEB_WORKER || env.INTERNAL_KEY_WEB_AI === env.INTERNAL_KEY_WORKER_AI)
      ) {
        ctx.addIssue({ code: 'custom', path: ['INTERNAL_KEY_WEB_AI'], message: 'must differ from the other pair keys' });
      }
      const stripe = env.STRIPE_WEBHOOK_SECRET;
      if (hasWorkerRole(roles.roles) && (stripe === undefined || stripe === DEV_STRIPE_WEBHOOK_SECRET || !stripe.startsWith('whsec_'))) {
        ctx.addIssue({
          code: 'custom',
          path: ['STRIPE_WEBHOOK_SECRET'],
          message: 'must be set to the real Stripe signing secret (whsec_…) in production',
        });
      }
    }
    if (env.TELEGRAM_MODE === 'webhook' && env.TELEGRAM_BOT_TOKEN && !env.TELEGRAM_WEBHOOK_SECRET) {
      ctx.addIssue({ code: 'custom', path: ['TELEGRAM_WEBHOOK_SECRET'], message: 'is required when TELEGRAM_MODE=webhook' });
    }
  });

export type RawEnv = z.input<typeof envSchema>;
type ParsedEnv = z.output<typeof envSchema>;

export type WorkerRole = 'jobs' | 'bot' | 'ingress' | 'rag';

export interface AppConfig {
  roles: ReadonlySet<WorkerRole>;
  env: ParsedEnv['NODE_ENV'];
  isProduction: boolean;
  host: string;
  port: number;
  log: { level: ParsedEnv['LOG_LEVEL']; pretty: boolean };
  product: { name: string; slug: string };
  database: { url: string; poolMax: number };
  redis: { url: string; queuePrefix: string; keyPrefix: string };
  internal: {
    secret: string;
    usingDevSecret: boolean;
    keys: { web: string; ai: string; webAi: string };
    webUrl: string;
    webPublicUrl: string;
    aiUrl: string;
  };
  timeouts: { webMs: number; aiChatMs: number; aiIngestMs: number };
  logAiQueries: boolean;
  outbox: { enabled: boolean; table: string; channel: string; pollMs: number; batchSize: number };
  stripe: { webhookSecret: string; secretKey: string | undefined };
  telegram: {
    mode: ParsedEnv['TELEGRAM_MODE'];
    token: string | undefined;
    botUsername: string;
    webhookSecret: string | undefined;
    webhookUrl: string | undefined;
    apiRoot: string;
    rateLimit: number;
    rateWindowS: number;
    actionTtlS: number;
    accountCacheTtlS: number;
  };
  smtp: { host: string; port: number; secure: boolean; user: string | undefined; password: string | undefined; from: string };
  devRoutes: boolean;
  dashboard: { user: string; password: string } | null;
  workersEnabled: boolean;
  privacyPurgeCron: string | null;
  billingRenewalsCron: string | null;
  paymentsReconcileCron: string | null;
  webhooks: { yookassaAllowedIps: string[]; trustedProxyHops: number };
  rag: RagConfig;
}

export const DEV_FALLBACK_SECRET = 'dev-internal-secret-not-for-production';

export const DEV_STRIPE_WEBHOOK_SECRET = 'whsec_test_local';

const MIN_PRODUCTION_SECRET_LENGTH = 32;

const ALL_ROLES: readonly WorkerRole[] = ['jobs', 'bot', 'ingress', 'rag'];
const PAIR_KEYS = ['INTERNAL_KEY_WEB_WORKER', 'INTERNAL_KEY_WORKER_AI', 'INTERNAL_KEY_WEB_AI'] as const;
type PairKey = (typeof PAIR_KEYS)[number];

type ParsedRoles = { ok: true; roles: Set<WorkerRole | 'all'> } | { ok: false; message: string };

export function parseRoles(value: string | undefined, isProduction: boolean): ParsedRoles {
  const raw = value ?? (isProduction ? 'jobs,bot,ingress' : 'all');
  const roles = new Set<WorkerRole | 'all'>();
  for (const entry of raw.split(',').map((part) => part.trim().toLowerCase()).filter((part) => part !== '')) {
    if (entry !== 'all' && !ALL_ROLES.includes(entry as WorkerRole)) return { ok: false, message: `unknown role "${entry}" (allowed: all, ${ALL_ROLES.join(', ')})` };
    roles.add(entry as WorkerRole | 'all');
  }
  if (roles.size === 0) return { ok: false, message: 'must list at least one role' };
  return { ok: true, roles };
}

function hasWorkerRole(roles: ReadonlySet<string>): boolean {
  return roles.has('all') || roles.has('jobs') || roles.has('bot') || roles.has('ingress');
}

function expandRoles(roles: ReadonlySet<WorkerRole | 'all'>): ReadonlySet<WorkerRole> {
  return new Set(roles.has('all') ? ALL_ROLES : ALL_ROLES.filter((role) => roles.has(role)));
}

export class ConfigError extends Error {
  constructor(public readonly issues: string[]) {
    super(`Invalid worker configuration:\n  - ${issues.join('\n  - ')}`);
    this.name = 'ConfigError';
  }
}

export function loadConfig(source: Record<string, string | undefined> = process.env): AppConfig {
  const parsed = envSchema.safeParse(withoutEmptyValues(source));
  if (!parsed.success) {
    throw new ConfigError(parsed.error.issues.map((issue) => `${issue.path.join('.') || '(root)'}: ${issue.message}`));
  }
  const env = parsed.data;
  const isProduction = env.NODE_ENV === 'production';
  const parsedRoles = parseRoles(env.WORKER_ROLES, isProduction);
  if (!parsedRoles.ok) throw new ConfigError([`WORKER_ROLES: ${parsedRoles.message}`]);
  const roles = expandRoles(parsedRoles.roles);
  const rag = parseRagConfig(withoutEmptyValues(source), isProduction && roles.has('rag'));
  if (!rag.ok) throw new ConfigError(rag.issues);
  const sharedSecret = env.INTERNAL_API_SECRET ?? DEV_FALLBACK_SECRET;
  const dashboard =
    env.QUEUE_DASHBOARD_USER && env.QUEUE_DASHBOARD_PASSWORD
      ? { user: env.QUEUE_DASHBOARD_USER, password: env.QUEUE_DASHBOARD_PASSWORD }
      : null;

  return Object.freeze({
    env: env.NODE_ENV,
    roles,
    isProduction,
    host: env.HOST,
    port: env.PORT,
    log: { level: env.LOG_LEVEL, pretty: env.LOG_PRETTY },
    product: { name: env.PRODUCT_NAME, slug: env.PRODUCT_SLUG },
    database: { url: env.DATABASE_URL, poolMax: env.DATABASE_POOL_MAX },
    redis: { url: env.REDIS_URL, queuePrefix: env.QUEUE_PREFIX, keyPrefix: `${env.PRODUCT_SLUG}:worker:` },
    internal: {
      secret: sharedSecret,
      usingDevSecret:
        env.INTERNAL_API_SECRET === undefined &&
        (env.INTERNAL_KEY_WEB_WORKER === undefined || env.INTERNAL_KEY_WORKER_AI === undefined || (roles.has('rag') && env.INTERNAL_KEY_WEB_AI === undefined)),
      keys: {
        web: env.INTERNAL_KEY_WEB_WORKER ?? sharedSecret,
        ai: env.INTERNAL_KEY_WORKER_AI ?? sharedSecret,
        webAi: env.INTERNAL_KEY_WEB_AI ?? sharedSecret,
      },
      webUrl: stripTrailingSlash(env.WEB_URL),
      webPublicUrl: stripTrailingSlash(env.WEB_PUBLIC_URL ?? env.WEB_URL),
      aiUrl: stripTrailingSlash(env.AI_URL),
    },
    timeouts: { webMs: env.WEB_TIMEOUT_MS, aiChatMs: env.AI_CHAT_TIMEOUT_MS, aiIngestMs: env.AI_INGEST_TIMEOUT_MS },
    logAiQueries: env.LOG_AI_QUERIES,
    outbox: {
      enabled: env.OUTBOX_ENABLED,
      table: env.OUTBOX_TABLE,
      channel: env.OUTBOX_CHANNEL,
      pollMs: env.OUTBOX_POLL_MS,
      batchSize: env.OUTBOX_BATCH_SIZE,
    },
    stripe: { webhookSecret: env.STRIPE_WEBHOOK_SECRET ?? DEV_STRIPE_WEBHOOK_SECRET, secretKey: env.STRIPE_SECRET_KEY },
    telegram: {
      mode: env.TELEGRAM_MODE,
      token: env.TELEGRAM_BOT_TOKEN,
      botUsername: env.TELEGRAM_BOT_USERNAME ?? `${env.PRODUCT_SLUG.replace(/-/g, '_')}_bot`,
      webhookSecret: env.TELEGRAM_WEBHOOK_SECRET,
      webhookUrl: env.TELEGRAM_WEBHOOK_URL,
      apiRoot: stripTrailingSlash(env.TELEGRAM_API_ROOT),
      rateLimit: env.BOT_RATE_LIMIT,
      rateWindowS: env.BOT_RATE_WINDOW_S,
      actionTtlS: env.BOT_ACTION_TTL_S,
      accountCacheTtlS: env.BOT_ACCOUNT_CACHE_TTL_S,
    },
    smtp: {
      host: env.SMTP_HOST,
      port: env.SMTP_PORT,
      secure: env.SMTP_SECURE,
      user: env.SMTP_USER,
      password: env.SMTP_PASSWORD,
      from: env.MAIL_FROM ?? `"${env.PRODUCT_NAME}" <no-reply@${env.PRODUCT_SLUG}.test>`,
    },
    devRoutes: env.DEV_ROUTES && !isProduction,
    dashboard,
    workersEnabled: env.WORKERS_ENABLED,
    privacyPurgeCron: env.PRIVACY_PURGE_CRON === '' ? null : env.PRIVACY_PURGE_CRON,
    billingRenewalsCron: env.BILLING_RENEWALS_CRON === 'off' ? null : env.BILLING_RENEWALS_CRON,
    paymentsReconcileCron: env.PAYMENTS_RECONCILE_CRON === 'off' ? null : env.PAYMENTS_RECONCILE_CRON,
    webhooks: {
      yookassaAllowedIps: env.YOOKASSA_ALLOWED_IPS.split(',')
        .map((entry) => entry.trim())
        .filter((entry) => entry !== ''),
      trustedProxyHops: env.WEBHOOK_TRUSTED_PROXY_HOPS,
    },
    rag: rag.config,
  });
}

export function loadEnvFile(): string | null {
  const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
  const candidates = [resolve(root, '.env')];
  if (process.env.NODE_ENV !== 'production') {
    candidates.push(resolve(root, '.env.example'));
  }
  for (const candidate of candidates) {
    if (existsSync(candidate)) {
      process.loadEnvFile(candidate);
      return candidate;
    }
  }
  return null;
}

function withoutEmptyValues(source: Record<string, string | undefined>): Record<string, string> {
  const result: Record<string, string> = {};
  for (const [key, value] of Object.entries(source)) {
    if (value !== undefined && value.trim() !== '') result[key] = value;
  }
  return result;
}

function stripTrailingSlash(value: string): string {
  return value.replace(/\/+$/, '');
}
