import { ConfigError, loadConfig, loadEnvFile } from '../config.js';
import { createLogger, errorFields } from '../logger.js';
import { migrate } from './migrate.js';
import { createPool } from './pool.js';

async function main(): Promise<void> {
  loadEnvFile();
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
  const logger = createLogger({ level: config.log.level, pretty: config.log.pretty, service: 'worker-migrate' });
  const pool = createPool(config.database.url, 1, logger);
  try {
    const applied = await migrate(pool, logger);
    logger.info({ applied }, applied.length > 0 ? 'worker schema migrated' : 'worker schema up to date');
  } catch (error) {
    logger.fatal(errorFields(error), 'worker migrations failed');
    process.exitCode = 1;
  } finally {
    await pool.end();
  }
}

void main();
