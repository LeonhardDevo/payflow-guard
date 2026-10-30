import { AsyncLocalStorage } from 'node:async_hooks';
import type { Transformer } from 'grammy';
import type { UserFromGetMe } from 'grammy/types';

export interface OutboundCall {
  method: string;
  payload: Record<string, unknown>;
}

const OFFLINE_LOG_SIZE = 200;

export class TelegramCapture {
  private readonly storage = new AsyncLocalStorage<OutboundCall[]>();
  private readonly offline: OutboundCall[] = [];
  private messageSeq = 1000;

  constructor(private readonly botInfo: () => UserFromGetMe) {}

  async capture<T>(fn: () => Promise<T>): Promise<{ result: T; outbound: OutboundCall[] }> {
    const outbound: OutboundCall[] = [];
    const result = await this.storage.run(outbound, fn);
    return { result, outbound };
  }

  offlineCalls(): OutboundCall[] {
    return [...this.offline];
  }

  clearOffline(): void {
    this.offline.length = 0;
  }

  transformer(offline: boolean): Transformer {
    return async (prev, method, payload, signal) => {
      const sink = this.storage.getStore();
      if (!sink && !offline) return prev(method, payload, signal);
      const call: OutboundCall = { method, payload: toPlain(payload) };
      if (sink) {
        sink.push(call);
      } else {
        this.offline.push(call);
        if (this.offline.length > OFFLINE_LOG_SIZE) this.offline.shift();
      }
      const result: never = this.fakeResult(method, call.payload) as never;
      return { ok: true, result };
    };
  }

  private fakeResult(method: string, payload: Record<string, unknown>): unknown {
    const chatId = typeof payload.chat_id === 'number' || typeof payload.chat_id === 'string' ? payload.chat_id : 0;
    const message = (): Record<string, unknown> => ({
      message_id: this.messageSeq++,
      date: Math.floor(Date.now() / 1000),
      chat: { id: chatId, type: 'private' },
      from: { id: this.botInfo().id, is_bot: true, first_name: this.botInfo().first_name, username: this.botInfo().username },
      ...(typeof payload.text === 'string' ? { text: payload.text } : {}),
    });
    switch (method) {
      case 'getMe':
        return this.botInfo();
      case 'getUpdates':
        return [];
      case 'sendMessage':
      case 'sendPhoto':
      case 'sendDocument':
      case 'forwardMessage':
      case 'editMessageText':
      case 'editMessageReplyMarkup':
        return message();
      case 'copyMessage':
        return { message_id: this.messageSeq++ };
      case 'getWebhookInfo':
        return { url: '', has_custom_certificate: false, pending_update_count: 0 };
      default:
        return true;
    }
  }
}

function toPlain(payload: unknown): Record<string, unknown> {
  if (payload === null || typeof payload !== 'object') return {};
  try {
    return JSON.parse(JSON.stringify(payload)) as Record<string, unknown>;
  } catch {
    return { ...(payload as Record<string, unknown>) };
  }
}

export function staticBotInfo(username: string, productName: string): UserFromGetMe {
  return {
    id: 1,
    is_bot: true,
    first_name: productName,
    username,
    can_join_groups: false,
    can_read_all_group_messages: false,
    supports_inline_queries: false,
    can_connect_to_business: false,
    has_main_web_app: false,
  } as UserFromGetMe;
}
