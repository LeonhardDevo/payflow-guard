export interface Migration {
  version: string;
  sql: string;
}

export const migrations: readonly Migration[] = [
  {
    version: '0001_webhook_events',
    sql: `
      CREATE TABLE IF NOT EXISTS worker.webhook_events (
        id            bigserial PRIMARY KEY,
        provider      varchar(32)  NOT NULL,
        event_id      varchar(255) NOT NULL,
        type          varchar(255) NOT NULL,
        payload       jsonb        NOT NULL,
        received_at   timestamptz  NOT NULL DEFAULT now(),
        processed_at  timestamptz  NULL,
        attempts      integer      NOT NULL DEFAULT 0,
        last_error    text         NULL,
        CONSTRAINT webhook_events_provider_event_unique UNIQUE (provider, event_id)
      );
      CREATE INDEX IF NOT EXISTS webhook_events_received_at_idx ON worker.webhook_events (received_at);
      CREATE INDEX IF NOT EXISTS webhook_events_pending_idx ON worker.webhook_events (provider, received_at) WHERE processed_at IS NULL;
    `,
  },
  {
    version: '0002_telegram_chats',
    sql: `
      CREATE TABLE IF NOT EXISTS worker.telegram_chats (
        telegram_user_id bigint      PRIMARY KEY,
        chat_id          bigint      NOT NULL,
        username         varchar(64) NULL,
        locale           varchar(5)  NULL,
        created_at       timestamptz NOT NULL DEFAULT now(),
        updated_at       timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT telegram_chats_locale_check CHECK (locale IS NULL OR locale IN ('ru', 'en'))
      );
    `,
  },
  {
    version: '0003_telegram_privacy_notice',
    sql: `
      ALTER TABLE worker.telegram_chats ADD COLUMN IF NOT EXISTS privacy_notice_at timestamptz NULL;
    `,
  },
];
