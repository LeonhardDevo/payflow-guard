export interface TelegramUser {
  id: number;
  username: string;
  firstName: string;
  languageCode: 'en' | 'ru';
}

export interface OutboundCall {
  method: string;
  payload: Record<string, unknown>;
}

let updateSeq = Math.floor(Date.now() / 1000) * 1000;

export function randomTelegramId(): number {
  return 100_000_000 + Math.floor(Math.random() * 1_900_000_000);
}

export function commandEntities(text: string): Array<{ type: 'bot_command'; offset: number; length: number }> {
  const match = /^\/[A-Za-z0-9_]+(?:@[A-Za-z0-9_]+)?/.exec(text);
  return match ? [{ type: 'bot_command', offset: 0, length: match[0].length }] : [];
}

export function messageUpdate(user: TelegramUser, text: string): Record<string, unknown> {
  updateSeq += 1;
  const from = {
    id: user.id,
    is_bot: false,
    first_name: user.firstName,
    username: user.username,
    language_code: user.languageCode,
  };
  const entities = commandEntities(text);
  return {
    update_id: updateSeq,
    message: {
      message_id: updateSeq % 1_000_000,
      date: Math.floor(Date.now() / 1000),
      chat: { id: user.id, type: 'private', first_name: user.firstName, username: user.username },
      from,
      text,
      ...(entities.length ? { entities } : {}),
    },
  };
}

export function outboundText(calls: readonly OutboundCall[]): string {
  return calls
    .filter((call) => call.method === 'sendMessage' || call.method === 'editMessageText')
    .map((call) => String(call.payload.text ?? ''))
    .join('\n');
}

export function startToken(deepLink: string): string | null {
  try {
    const url = new URL(deepLink);
    return url.searchParams.get('start');
  } catch {
    return null;
  }
}
