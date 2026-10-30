import type pg from 'pg';
import type { Locale } from '../i18n/index.js';

export interface ChatPreferences {
  getLocale(telegramUserId: number): Promise<Locale | null>;
  setLocale(telegramUserId: number, chatId: number, locale: Locale, username?: string | null): Promise<void>;
  markPrivacyNoticeShown(telegramUserId: number, chatId: number, username?: string | null): Promise<boolean>;
  forget(telegramUserIds: readonly number[]): Promise<number>;
}

export class PgChatPreferences implements ChatPreferences {
  private readonly table: string;

  constructor(
    private readonly pool: pg.Pool,
    schema = 'worker',
  ) {
    if (!/^[a-z_][a-z0-9_]*$/.test(schema)) throw new Error(`Unsafe schema name: ${schema}`);
    this.table = `"${schema}".telegram_chats`;
  }

  async getLocale(telegramUserId: number): Promise<Locale | null> {
    const { rows } = await this.pool.query<{ locale: string | null }>(`SELECT locale FROM ${this.table} WHERE telegram_user_id = $1`, [
      telegramUserId,
    ]);
    const locale = rows[0]?.locale;
    return locale === 'ru' || locale === 'en' ? locale : null;
  }

  async setLocale(telegramUserId: number, chatId: number, locale: Locale, username: string | null = null): Promise<void> {
    await this.pool.query(
      `INSERT INTO ${this.table} (telegram_user_id, chat_id, username, locale)
       VALUES ($1, $2, $3, $4)
       ON CONFLICT (telegram_user_id)
       DO UPDATE SET chat_id = EXCLUDED.chat_id, username = COALESCE(EXCLUDED.username, ${this.table}.username),
                     locale = EXCLUDED.locale, updated_at = now()`,
      [telegramUserId, chatId, username, locale],
    );
  }

  async markPrivacyNoticeShown(telegramUserId: number, chatId: number, username: string | null = null): Promise<boolean> {
    const result = await this.pool.query(
      `INSERT INTO ${this.table} (telegram_user_id, chat_id, username, privacy_notice_at)
       VALUES ($1, $2, $3, now())
       ON CONFLICT (telegram_user_id)
       DO UPDATE SET privacy_notice_at = now(), chat_id = EXCLUDED.chat_id, updated_at = now()
       WHERE ${this.table}.privacy_notice_at IS NULL
       RETURNING telegram_user_id`,
      [telegramUserId, chatId, username],
    );
    return (result.rowCount ?? 0) > 0;
  }

  async forget(telegramUserIds: readonly number[]): Promise<number> {
    if (telegramUserIds.length === 0) return 0;
    const result = await this.pool.query(`DELETE FROM ${this.table} WHERE telegram_user_id = ANY($1::bigint[])`, [[...telegramUserIds]]);
    return result.rowCount ?? 0;
  }
}

export class MemoryChatPreferences implements ChatPreferences {
  readonly locales = new Map<number, Locale>();
  readonly privacyNoticeShown = new Set<number>();

  constructor(private readonly options: { privacyNoticeSeen?: boolean } = {}) {}

  async getLocale(telegramUserId: number): Promise<Locale | null> {
    return this.locales.get(telegramUserId) ?? null;
  }

  async setLocale(telegramUserId: number, _chatId: number, locale: Locale): Promise<void> {
    this.locales.set(telegramUserId, locale);
  }

  async markPrivacyNoticeShown(telegramUserId: number): Promise<boolean> {
    if (this.options.privacyNoticeSeen || this.privacyNoticeShown.has(telegramUserId)) return false;
    this.privacyNoticeShown.add(telegramUserId);
    return true;
  }

  async forget(telegramUserIds: readonly number[]): Promise<number> {
    let removed = 0;
    for (const id of telegramUserIds) {
      const had = this.locales.delete(id) || this.privacyNoticeShown.has(id);
      this.privacyNoticeShown.delete(id);
      if (had) removed++;
    }
    return removed;
  }
}
