import { createApp } from './app.js';
import { ConfigError, loadConfig, loadEnvFile } from './config.js';
import { createLogger, errorFields } from './logger.js';

const SHUTDOWN_TIMEOUT_MS = 15_000;

async function main(): Promise<void> {
  const envFile = loadEnvFile();
  let config;
  try {
    config = loadConfig();
  } catch (error) {
    if (error instanceof ConfigError) {
      process.stderr.write(`${error.message}\n`);
      process.exit(1);
    }
    throw error;
  }
  const logger = createLogger({ level: config.log.level, pretty: config.log.pretty });
  logger.info({ envFile, env: config.env }, 'starting worker');
  if (config.internal.usingDevSecret) {
    logger.warn('INTERNAL_API_SECRET is empty; using the built-in development secret (run `npm run setup` to share one secret across services)');
  }

  const app = await createApp(config, logger);
  let stopping = false;
  const shutdown = (signal: string): void => {
    if (stopping) return;
    stopping = true;
    logger.info({ signal }, 'shutting down');
    const timer = setTimeout(() => {
      logger.error('shutdown timed out; exiting');
      process.exit(1);
    }, SHUTDOWN_TIMEOUT_MS);
    timer.unref();
    app
      .stop()
      .then(() => process.exit(0))
      .catch((error: unknown) => {
        logger.error(errorFields(error), 'shutdown failed');
        process.exit(1);
      });
  };
  process.on('SIGINT', () => shutdown('SIGINT'));
  process.on('SIGTERM', () => shutdown('SIGTERM'));
  process.on('unhandledRejection', (reason) => logger.error(errorFields(reason), 'unhandled promise rejection'));

  try {
    await app.start();
  } catch (error) {
    logger.fatal(errorFields(error), 'worker failed to start');
    await app.stop().catch(() => undefined);
    process.exit(1);
  }
}

void main();
