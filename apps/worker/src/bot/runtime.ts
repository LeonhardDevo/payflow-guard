import { setTimeout as sleep } from 'node:timers/promises';
import type { Update } from 'grammy/types';
import type { AppConfig } from '../config.js';
import type { Translator } from '../i18n/index.js';
import { errorFields, type Logger } from '../logger.js';
import type { JobEnqueuer } from '../queues/enqueuer.js';
import { JOB_NAMES, QUEUES } from '../queues/names.js';
import { telegramUpdateJobId } from '../queues/defaults.js';
import { grammySignal, type BotHandle } from './createBot.js';
import type { BotRegistry } from './types.js';

export const ALLOWED_UPDATES = ['message', 'callback_query'] as const;
const POLL_TIMEOUT_S = 25;

export async function enqueueUpdate(enqueuer: JobEnqueuer, update: Update): Promise<{ jobId: string; created: boolean }> {
  return enqueuer.add(QUEUES.telegramUpdates, JOB_NAMES.telegramUpdate, update, telegramUpdateJobId(update.update_id));
}

export class TelegramRuntime {
  private stopped = true;
  private abort: AbortController | null = null;
  private loop: Promise<void> | null = null;

  constructor(
    private readonly handle: BotHandle,
    private readonly config: AppConfig['telegram'],
    private readonly enqueuer: JobEnqueuer,
    private readonly registry: BotRegistry,
    private readonly translator: Translator,
    private readonly logger: Logger,
  ) {}

  get mode(): 'polling' | 'webhook' | 'disabled' {
    if (this.handle.offline) return 'disabled';
    return this.config.mode;
  }

  async start(): Promise<void> {
    this.stopped = false;
    await this.handle.init(this.logger);
    if (this.handle.offline) {
      this.logger.info({ requestedMode: this.config.mode }, 'telegram bot running offline (no token); outbound calls are captured');
      return;
    }
    await this.registerCommands();
    if (this.config.mode === 'webhook') {
      await this.registerWebhook();
    } else if (this.config.mode === 'polling') {
      await this.handle.bot.api.deleteWebhook({ drop_pending_updates: false }).catch((error: unknown) => {
        this.logger.warn(errorFields(error), 'telegram deleteWebhook failed');
      });
      this.abort = new AbortController();
      this.loop = this.pollLoop(this.abort.signal);
    }
    this.logger.info({ mode: this.config.mode }, 'telegram bot started');
  }

  async stop(): Promise<void> {
    this.stopped = true;
    this.abort?.abort();
    await this.loop?.catch(() => undefined);
    this.loop = null;
  }

  private async registerCommands(): Promise<void> {
    const api = this.handle.bot.api;
    try {
      for (const locale of ['en', 'ru'] as const) {
        const commands = this.registry.commands.map((command) => ({
          command: command.command,
          description: this.translator.t(locale, command.descriptionKey).slice(0, 256),
        }));
        await api.setMyCommands(commands, locale === 'en' ? {} : { language_code: locale });
      }
    } catch (error) {
      this.logger.warn(errorFields(error), 'telegram setMyCommands failed');
    }
  }

  private async registerWebhook(): Promise<void> {
    if (!this.config.webhookUrl) {
      this.logger.warn('TELEGRAM_MODE=webhook but TELEGRAM_WEBHOOK_URL is empty; register the webhook manually');
      return;
    }
    try {
      await this.handle.bot.api.setWebhook(this.config.webhookUrl, {
        secret_token: this.config.webhookSecret,
        allowed_updates: [...ALLOWED_UPDATES],
      });
      this.logger.info({ url: this.config.webhookUrl }, 'telegram webhook registered');
    } catch (error) {
      this.logger.error(errorFields(error), 'telegram setWebhook failed');
    }
  }

  private async pollLoop(signal: AbortSignal): Promise<void> {
    let offset: number | undefined;
    let backoffMs = 1000;
    while (!this.stopped) {
      try {
        const updates = await this.handle.bot.api.getUpdates(
          { offset, timeout: POLL_TIMEOUT_S, allowed_updates: [...ALLOWED_UPDATES] },
          grammySignal(signal),
        );
        for (const update of updates) {
          await enqueueUpdate(this.enqueuer, update);
          offset = update.update_id + 1;
        }
        backoffMs = 1000;
      } catch (error) {
        if (this.stopped || signal.aborted) break;
        this.logger.warn({ backoffMs, ...errorFields(error) }, 'telegram polling failed; backing off');
        await sleep(backoffMs, undefined, { signal }).catch(() => undefined);
        backoffMs = Math.min(backoffMs * 2, 30_000);
      }
    }
  }
}
