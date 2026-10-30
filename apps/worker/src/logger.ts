import { pino, type DestinationStream, type Logger, type LoggerOptions } from 'pino';

export type { Logger } from 'pino';

export const REDACT_PATHS = [
  'req.headers.authorization',
  'req.headers["x-signature"]',
  'req.headers["stripe-signature"]',
  'req.headers["x-telegram-bot-api-secret-token"]',
  'headers.authorization',
  'headers["x-signature"]',
  '*.password',
  '*.token',
  '*.secret',
  '*.api_key',
  '*.apiKey',
  'config.internal.secret',
  'config.internal.keys.web',
  'config.internal.keys.ai',
  'config.internal.keys.webAi',
  'config.rag.settingsKey',
  'config.rag.providerKeys.*',
  'config.rag.embeddings.apiKey',
  'config.telegram.token',
  'config.telegram.webhookSecret',
  'config.stripe.webhookSecret',
  'config.stripe.secretKey',
  'config.smtp.password',
  'config.dashboard.password',
];

export interface LoggerSettings {
  level: LoggerOptions['level'];
  pretty?: boolean;
  service?: string;
  destination?: DestinationStream;
}

export function createLogger(settings: LoggerSettings): Logger {
  const options: LoggerOptions = {
    level: settings.level ?? 'info',
    base: { service: settings.service ?? 'worker' },
    redact: { paths: REDACT_PATHS, censor: '[redacted]' },
    timestamp: pino.stdTimeFunctions.isoTime,
    formatters: {
      level: (label) => ({ level: label }),
    },
  };
  if (settings.pretty) {
    return pino({
      ...options,
      transport: { target: 'pino-pretty', options: { colorize: true, translateTime: 'SYS:HH:MM:ss.l' } },
    });
  }
  return settings.destination ? pino(options, settings.destination) : pino(options);
}

export function createSilentLogger(): Logger {
  return pino({ level: 'silent' });
}

export function errorFields(error: unknown): { err: Error } {
  return { err: error instanceof Error ? error : new Error(typeof error === 'string' ? error : JSON.stringify(error)) };
}
