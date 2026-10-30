import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { formatViolations, isExternalRequest, isLocalHost } from './network.ts';

describe('isExternalRequest', () => {
  it('allows the local machine in every spelling', () => {
    for (const url of [
      'http://localhost:8100/build/app.js',
      'http://127.0.0.1:8100/',
      'http://127.0.0.2:5173/@vite/client',
      'http://[::1]:5173/',
      'ws://localhost:5173/',
      'http://app.localhost/',
    ]) {
      assert.equal(isExternalRequest(url), false, url);
    }
  });

  it('ignores non-network schemes', () => {
    for (const url of ['data:font/woff2;base64,AAAA', 'blob:http://localhost/uuid', 'about:blank', 'chrome-extension://x/y.js']) {
      assert.equal(isExternalRequest(url), false, url);
    }
  });

  it('flags CDNs, font services and hot-linked images', () => {
    for (const url of [
      'https://fonts.googleapis.com/css2?family=Inter',
      'https://fonts.bunny.net/css?family=instrument-sans',
      'https://cdn.jsdelivr.net/npm/vue',
      'https://www.gravatar.com/avatar/x',
      'https://unpkg.com/lucide',
      'http://localhost.evil.com/x',
    ]) {
      assert.equal(isExternalRequest(url), true, url);
    }
  });

  it('honours explicitly allowed hosts', () => {
    assert.equal(isExternalRequest('http://mailpit:8025/', ['mailpit']), false);
    assert.equal(isLocalHost('MAILPIT', ['mailpit']), true);
  });

  it('treats unparsable URLs as local (nothing was requested)', () => {
    assert.equal(isExternalRequest('not a url'), false);
  });
});

describe('formatViolations', () => {
  it('deduplicates by URL and keeps the resource type', () => {
    const message = formatViolations([
      { url: 'https://fonts.googleapis.com/x', resourceType: 'stylesheet', frameUrl: 'http://127.0.0.1:8100/' },
      { url: 'https://fonts.googleapis.com/x', resourceType: 'stylesheet', frameUrl: 'http://127.0.0.1:8100/' },
      { url: 'https://cdn.example.com/a.js', resourceType: 'script', frameUrl: null },
    ]);
    assert.match(message, /reach 2 non-localhost URL/);
    assert.match(message, /\[stylesheet\] https:\/\/fonts\.googleapis\.com\/x/);
    assert.match(message, /\[script\] https:\/\/cdn\.example\.com\/a\.js$/m);
  });
});
