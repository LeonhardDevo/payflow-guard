import { randomBytes } from 'node:crypto';
import type { Redis } from 'ioredis';
import type { Locale } from '../i18n/index.js';

export interface StoredAction {
  type: string;
  payload: Record<string, unknown>;
  telegram_user_id: number | null;
  chat_id: number | null;
  workspace_id: number | null;
  user_id: number | null;
  locale: Locale | null;
  created_at: number;
}

export const ACTION_CALLBACK_PREFIX = 'a:';
const ID_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

export function newActionId(length = 12): string {
  const bytes = randomBytes(length);
  let id = '';
  for (const byte of bytes) id += ID_ALPHABET[byte % ID_ALPHABET.length];
  return id;
}

export interface ActionStore {
  put(action: StoredAction, ttlS: number): Promise<string>;
  get(id: string): Promise<StoredAction | null>;
}

export interface RateLimiter {
  hit(key: string, windowS: number): Promise<number>;
}

export interface KeyValueCache {
  get(key: string): Promise<string | null>;
  set(key: string, value: string, ttlS: number): Promise<void>;
  del(key: string): Promise<void>;
}

export class RedisActionStore implements ActionStore {
  constructor(
    private readonly redis: Redis,
    private readonly prefix: string,
  ) {}

  async put(action: StoredAction, ttlS: number): Promise<string> {
    const id = newActionId();
    await this.redis.set(`${this.prefix}action:${id}`, JSON.stringify(action), 'EX', ttlS);
    return id;
  }

  async get(id: string): Promise<StoredAction | null> {
    const raw = await this.redis.get(`${this.prefix}action:${id}`);
    return raw ? (JSON.parse(raw) as StoredAction) : null;
  }
}

export class RedisRateLimiter implements RateLimiter {
  constructor(
    private readonly redis: Redis,
    private readonly prefix: string,
  ) {}

  async hit(key: string, windowS: number): Promise<number> {
    const bucket = Math.floor(Date.now() / 1000 / windowS);
    const redisKey = `${this.prefix}rl:${key}:${bucket}`;
    const results = await this.redis.multi().incr(redisKey).expire(redisKey, windowS + 1).exec();
    const count = results?.[0]?.[1];
    return typeof count === 'number' ? count : 1;
  }
}

export class RedisCache implements KeyValueCache {
  constructor(
    private readonly redis: Redis,
    private readonly prefix: string,
  ) {}

  async get(key: string): Promise<string | null> {
    return this.redis.get(`${this.prefix}${key}`);
  }

  async set(key: string, value: string, ttlS: number): Promise<void> {
    if (ttlS <= 0) return;
    await this.redis.set(`${this.prefix}${key}`, value, 'EX', ttlS);
  }

  async del(key: string): Promise<void> {
    await this.redis.del(`${this.prefix}${key}`);
  }
}

export class MemoryActionStore implements ActionStore {
  readonly items = new Map<string, { action: StoredAction; expiresAt: number }>();

  async put(action: StoredAction, ttlS: number): Promise<string> {
    const id = newActionId();
    this.items.set(id, { action, expiresAt: Date.now() + ttlS * 1000 });
    return id;
  }

  async get(id: string): Promise<StoredAction | null> {
    const item = this.items.get(id);
    if (!item || item.expiresAt < Date.now()) return null;
    return item.action;
  }
}

export class MemoryRateLimiter implements RateLimiter {
  private readonly counters = new Map<string, number>();

  async hit(key: string, windowS: number): Promise<number> {
    const bucket = `${key}:${Math.floor(Date.now() / 1000 / windowS)}`;
    const count = (this.counters.get(bucket) ?? 0) + 1;
    this.counters.set(bucket, count);
    return count;
  }
}

export class MemoryCache implements KeyValueCache {
  private readonly items = new Map<string, { value: string; expiresAt: number }>();

  async get(key: string): Promise<string | null> {
    const item = this.items.get(key);
    if (!item || item.expiresAt < Date.now()) return null;
    return item.value;
  }

  async set(key: string, value: string, ttlS: number): Promise<void> {
    if (ttlS <= 0) return;
    this.items.set(key, { value, expiresAt: Date.now() + ttlS * 1000 });
  }

  async del(key: string): Promise<void> {
    this.items.delete(key);
  }
}
