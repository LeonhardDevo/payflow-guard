import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { emojiIn, isConsoleProblem } from './guards.ts';

const message = (type: string, text: string) => ({ type: () => type, text: () => text }) as Parameters<typeof isConsoleProblem>[0];

describe('emojiIn', () => {
  it('finds pictographs and keeps typographic marks', () => {
    assert.deepEqual(emojiIn('Paid \u{1F389} on time ✅'), ['\u{1F389}', '✅']);
    assert.deepEqual(emojiIn('© 2026 PayFlow™ • №5 — ✓ done'), []);
    assert.deepEqual(emojiIn('Оплачено вовремя'), []);
  });
});

describe('isConsoleProblem', () => {
  it('fails on errors and handled business responses do not count', () => {
    assert.equal(isConsoleProblem(message('error', 'Uncaught TypeError: x is undefined')), true);
    assert.equal(isConsoleProblem(message('error', 'Failed to load resource: the server responded with a status of 500 (Internal Server Error)')), true);
    assert.equal(isConsoleProblem(message('error', 'Failed to load resource: the server responded with a status of 409 (Conflict)')), false);
    assert.equal(isConsoleProblem(message('warning', 'deprecated API')), false);
  });
});
