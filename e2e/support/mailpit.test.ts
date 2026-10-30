import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { extractLinks, findLink } from './mailpit.ts';

const HTML = `
<p>You were invited.</p>
<a href="http://127.0.0.1:8100/invitations/abc123?expires=1&amp;signature=ff">Accept</a>
<a href='https://example.test/help'>Help</a>
<img src="http://127.0.0.1:8100/logo.png">
`;
const TEXT = 'Verify: http://127.0.0.1:8100/email/verify/7/hash?expires=99&signature=aa\nThanks';

describe('extractLinks', () => {
  it('decodes entities and keeps document order without duplicates', () => {
    const links = extractLinks(HTML + HTML, TEXT);
    assert.equal(links[0], 'http://127.0.0.1:8100/invitations/abc123?expires=1&signature=ff');
    assert.ok(links.includes('https://example.test/help'));
    assert.ok(links.includes('http://127.0.0.1:8100/email/verify/7/hash?expires=99&signature=aa'));
    assert.equal(new Set(links).size, links.length);
  });

  it('ignores relative and mailto links', () => {
    assert.deepEqual(extractLinks('<a href="/relative">x</a><a href="mailto:a@b.c">m</a>'), []);
  });
});

describe('findLink', () => {
  it('finds the first link matching a path pattern in HTML or text', () => {
    assert.equal(findLink({ HTML, Text: TEXT }, /\/invitations\//), 'http://127.0.0.1:8100/invitations/abc123?expires=1&signature=ff');
    assert.equal(findLink({ HTML: '', Text: TEXT }, /\/email\/verify\//), 'http://127.0.0.1:8100/email/verify/7/hash?expires=99&signature=aa');
    assert.equal(findLink({ HTML, Text: TEXT }, /\/nope\//), null);
  });
});
