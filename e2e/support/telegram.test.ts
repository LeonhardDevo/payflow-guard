import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { commandEntities, messageUpdate, outboundText, randomTelegramId, startToken } from './telegram.ts';

const user = { id: 123456789, username: 'e2e_user', firstName: 'E2E', languageCode: 'ru' as const };

describe('messageUpdate', () => {
  it('builds a private-chat message update with a bot_command entity', () => {
    const update = messageUpdate(user, '/start abc123') as { update_id: number; message: Record<string, unknown> };
    assert.ok(update.update_id > 0);
    assert.equal(update.message.text, '/start abc123');
    assert.deepEqual(update.message.entities, [{ type: 'bot_command', offset: 0, length: 6 }]);
    assert.deepEqual((update.message.chat as { id: number; type: string }).type, 'private');
    assert.equal((update.message.from as { language_code: string }).language_code, 'ru');
  });

  it('uses increasing update ids and omits entities for free text', () => {
    const a = messageUpdate(user, 'hello') as { update_id: number; message: Record<string, unknown> };
    const b = messageUpdate(user, 'hello') as { update_id: number };
    assert.ok(b.update_id > a.update_id);
    assert.equal(a.message.entities, undefined);
  });
});

describe('helpers', () => {
  it('commandEntities handles @botname suffixes', () => {
    assert.deepEqual(commandEntities('/whoami@payflow_bot'), [{ type: 'bot_command', offset: 0, length: 19 }]);
    assert.deepEqual(commandEntities('no command'), []);
  });

  it('startToken parses deep links', () => {
    assert.equal(startToken('https://t.me/payflow_bot?start=tok_123'), 'tok_123');
    assert.equal(startToken('nonsense'), null);
  });

  it('outboundText joins message texts only', () => {
    assert.equal(
      outboundText([
        { method: 'sendMessage', payload: { text: 'Linked' } },
        { method: 'setMyCommands', payload: {} },
        { method: 'editMessageText', payload: { text: 'Done' } },
      ]),
      'Linked\nDone',
    );
  });

  it('randomTelegramId stays in the realistic range', () => {
    for (let i = 0; i < 100; i++) {
      const id = randomTelegramId();
      assert.ok(id >= 100_000_000 && id < 2_000_000_000);
    }
  });
});
