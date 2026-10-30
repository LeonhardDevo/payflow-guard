import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { fernetKey, isFernetKey, isLaravelAppKey, laravelAppKey, randomHex, stripeWebhookSecret } from '../lib/secrets.ts';

describe('secret generators', () => {
  it('randomHex has the requested entropy and differs between calls', () => {
    const a = randomHex(32);
    assert.match(a, /^[0-9a-f]{64}$/);
    assert.notEqual(a, randomHex(32));
  });

  it('fernetKey is a valid 32-byte URL-safe base64 key', () => {
    for (let i = 0; i < 50; i++) {
      const key = fernetKey();
      assert.equal(isFernetKey(key), true, key);
      assert.doesNotMatch(key, /[+/]/);
    }
  });

  it('isFernetKey rejects malformed keys', () => {
    assert.equal(isFernetKey('short'), false);
    assert.equal(isFernetKey(`${'A'.repeat(43)}`), false);
    assert.equal(isFernetKey(`${'+'.repeat(43)}=`), false);
  });

  it('laravelAppKey matches the AES-256-CBC format', () => {
    const key = laravelAppKey();
    assert.equal(isLaravelAppKey(key), true);
    assert.equal(isLaravelAppKey('base64:dGVzdA=='), false);
    assert.equal(isLaravelAppKey('plain'), false);
  });

  it('stripeWebhookSecret looks like a Stripe signing secret', () => {
    assert.match(stripeWebhookSecret(), /^whsec_[0-9a-f]{48}$/);
  });
});
