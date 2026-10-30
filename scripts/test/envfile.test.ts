import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { formatEnvValue, isPlaceholder, parseEnvText, upsertEnv } from '../lib/envfile.ts';

describe('parseEnvText', () => {
  it('resolves quotes and ignores comments', () => {
    const values = parseEnvText('# comment\nA=1\nB="two words"\nC=\n');
    assert.deepEqual(values, { A: '1', B: 'two words', C: '' });
  });
});

describe('formatEnvValue', () => {
  it('keeps safe values bare, including base64 and URLs', () => {
    assert.equal(formatEnvValue('base64:AbC+/='), 'base64:AbC+/=');
    assert.equal(formatEnvValue('postgres://app:app@127.0.0.1:5441/payflow'), 'postgres://app:app@127.0.0.1:5441/payflow');
  });
  it('prefers plain double quotes, then literal single quotes', () => {
    assert.equal(formatEnvValue('PayFlow Guard'), '"PayFlow Guard"');
    assert.equal(formatEnvValue('say "hi"'), `'say "hi"'`);
    assert.equal(formatEnvValue('C:\\path'), `'C:\\path'`);
    assert.equal(formatEnvValue('cost $5'), `'cost $5'`);
  });
  it('round-trips through the parser', () => {
    for (const value of ['PayFlow Guard', 'a#b', 'x"y', 'line\\path', 'cost $5']) {
      assert.equal(parseEnvText(`K=${formatEnvValue(value)}\n`).K, value);
    }
  });
});

describe('isPlaceholder', () => {
  it('detects empty and template values', () => {
    for (const value of [
      undefined,
      '',
      '  ',
      'null',
      'change-me',
      'CHANGE_ME_please',
      'local-dev-internal-secret-change-me',
      'whsec_local_dev_secret_change_me',
      '<generate>',
      '${SECRET}',
      'xxxx',
      'base64:',
    ]) {
      assert.equal(isPlaceholder(value), true, String(value));
    }
  });
  it('accepts real values', () => {
    for (const value of ['whsec_test_local', 'a1b2c3', 'base64:AAAA']) assert.equal(isPlaceholder(value), false, value);
  });
});

describe('upsertEnv', () => {
  it('replaces in place and preserves comments and order', () => {
    const text = '# header\nAPP_KEY=\nDB_PORT=5441\n';
    assert.equal(upsertEnv(text, { APP_KEY: 'base64:k' }), '# header\nAPP_KEY=base64:k\nDB_PORT=5441\n');
  });
  it('appends unknown keys after a blank line', () => {
    assert.equal(upsertEnv('A=1\n', { B: '2' }), 'A=1\n\nB=2\n');
  });
  it('handles export prefixes and removes duplicate assignments', () => {
    assert.equal(upsertEnv('export A=1\nA=2\nC=3\n', { A: '9' }), 'A=9\nC=3\n');
  });
  it('keeps CRLF line endings', () => {
    assert.equal(upsertEnv('A=1\r\nB=2\r\n', { B: '3' }), 'A=1\r\nB=3\r\n');
  });
  it('does not touch keys that merely share a prefix', () => {
    assert.equal(upsertEnv('APP_KEY_OLD=x\nAPP_KEY=\n', { APP_KEY: 'k' }), 'APP_KEY_OLD=x\nAPP_KEY=k\n');
  });
  it('creates content for an empty file', () => {
    assert.equal(upsertEnv('', { A: '1' }), 'A=1\n');
  });
});
