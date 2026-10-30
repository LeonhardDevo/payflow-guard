import { describe, expect, it } from 'vitest';
import {
  buttonLabel,
  escapeHtml,
  isPublicHttpUrl,
  markdownToTelegramHtml,
  splitTelegramHtml,
  stripHtml,
  toTelegramHtml,
} from '../src/bot/format.js';

describe('toTelegramHtml', () => {
  it('maps common tags onto the Telegram subset', () => {
    expect(toTelegramHtml('<p>Hello <strong>bold</strong> and <em>it</em></p><p>Next</p>')).toBe('Hello <b>bold</b> and <i>it</i>\n\nNext');
  });

  it('renders lists and line breaks', () => {
    expect(toTelegramHtml('<ul><li>One</li><li>Two</li></ul>')).toBe('• One\n• Two');
    expect(toTelegramHtml('<ol><li>A</li><li>B</li></ol>')).toBe('1. A\n2. B');
    expect(toTelegramHtml('a<br>b<br/>c')).toBe('a\nb\nc');
  });

  it('turns headings into bold lines', () => {
    expect(toTelegramHtml('<h2>Title</h2><p>Body</p>')).toBe('<b>Title</b>\nBody');
  });

  it('balances unclosed tags and drops stray closers', () => {
    expect(toTelegramHtml('<b>open <i>nested')).toBe('<b>open <i>nested</i></b>');
    expect(toTelegramHtml('text</b> more')).toBe('text more');
    expect(toTelegramHtml('<b>x<i>y</b>z</i>')).toBe('<b>x<i>y</i></b>z');
  });

  it('keeps safe links and strips unsafe ones', () => {
    expect(toTelegramHtml('<a href="https://ex.com/a?b=1&amp;c=2">x</a>')).toBe('<a href="https://ex.com/a?b=1&amp;c=2">x</a>');
    expect(toTelegramHtml('<a href="javascript:alert(1)">x</a>')).toBe('x');
    expect(toTelegramHtml('<a onclick="x">y</a>')).toBe('y');
  });

  it('drops scripts, styles and unknown tags but keeps their safe text', () => {
    expect(toTelegramHtml('<script>alert(1)</script><span>hi</span><table><tr><td>a</td><td>b</td></tr></table>')).toBe('hia b');
  });

  it('escapes stray angle brackets and ampersands but keeps entities', () => {
    expect(toTelegramHtml('5 < 7 & 8 > 2 &amp; &lt;ok&gt;')).toBe('5 &lt; 7 &amp; 8 &gt; 2 &amp; &lt;ok&gt;');
  });

  it('supports spoilers and code blocks', () => {
    expect(toTelegramHtml('<span class="tg-spoiler">s</span> <pre>code</pre>')).toBe('<tg-spoiler>s</tg-spoiler> <pre>code</pre>');
  });
});

describe('markdownToTelegramHtml', () => {
  it('converts the basic markdown subset and escapes HTML', () => {
    expect(markdownToTelegramHtml('# Title\n**bold** and *it* `x<y`\n- item\n[link](https://ex.com)')).toBe(
      '<b>Title</b>\n<b>bold</b> and <i>it</i> <code>x&lt;y</code>\n• item\n<a href="https://ex.com">link</a>',
    );
  });
});

describe('message helpers', () => {
  it('splits long HTML on paragraph boundaries under the limit', () => {
    const paragraph = `<b>${'x'.repeat(300)}</b>`;
    const html = Array.from({ length: 20 }, () => paragraph).join('\n\n');
    const parts = splitTelegramHtml(html, 1000);
    expect(parts.length).toBeGreaterThan(1);
    for (const part of parts) {
      expect(part.length).toBeLessThanOrEqual(1000);
      expect(part.match(/<b>/g)?.length).toBe(part.match(/<\/b>/g)?.length);
    }
  });

  it('hard-splits a single oversized paragraph', () => {
    const parts = splitTelegramHtml('y'.repeat(2500), 1000);
    expect(parts.join('')).toBe('y'.repeat(2500));
    expect(parts.every((part) => part.length <= 1000)).toBe(true);
  });

  it('escapes, strips and labels', () => {
    expect(escapeHtml('<a href="x">&</a>')).toBe('&lt;a href=&quot;x&quot;&gt;&amp;&lt;/a&gt;');
    expect(stripHtml('<b>a &amp; b</b><br>c')).toBe('a & b\nc');
    expect(buttonLabel('a'.repeat(60))).toHaveLength(48);
  });

  it('detects URLs Telegram accepts in buttons', () => {
    expect(isPublicHttpUrl('https://app.example.com/settings')).toBe(true);
    expect(isPublicHttpUrl('http://127.0.0.1:8100/x')).toBe(false);
    expect(isPublicHttpUrl('http://localhost:8100')).toBe(false);
    expect(isPublicHttpUrl('https://payflow.test')).toBe(false);
    expect(isPublicHttpUrl('http://192.168.1.10')).toBe(false);
    expect(isPublicHttpUrl('ftp://example.com')).toBe(false);
    expect(isPublicHttpUrl('not a url')).toBe(false);
  });
});
