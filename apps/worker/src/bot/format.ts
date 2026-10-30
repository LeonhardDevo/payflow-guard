export const TELEGRAM_MESSAGE_LIMIT = 4096;

const TAG_MAP: Record<string, string> = {
  b: 'b',
  strong: 'b',
  i: 'i',
  em: 'i',
  u: 'u',
  ins: 'u',
  s: 's',
  strike: 's',
  del: 's',
  code: 'code',
  pre: 'pre',
  blockquote: 'blockquote',
  'tg-spoiler': 'tg-spoiler',
  a: 'a',
};

const BLOCK_TAGS = new Set(['p', 'div', 'section', 'article', 'header', 'footer', 'table', 'tr']);
const HEADING_TAGS = new Set(['h1', 'h2', 'h3', 'h4', 'h5', 'h6']);
const DROP_CONTENT_TAGS = new Set(['script', 'style', 'head', 'title']);

export function escapeHtml(value: string): string {
  return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function escapeTextRun(value: string): string {
  return value
    .replace(/&(?!(?:[a-zA-Z][a-zA-Z0-9]{1,31}|#\d{1,7}|#x[0-9a-fA-F]{1,6});)/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

export function isSafeHref(href: string): boolean {
  return /^(https?:\/\/|tg:\/\/|mailto:)/i.test(href.trim());
}

export function isPublicHttpUrl(value: string): boolean {
  let url: URL;
  try {
    url = new URL(value);
  } catch {
    return false;
  }
  if (url.protocol !== 'https:' && url.protocol !== 'http:') return false;
  const host = url.hostname.toLowerCase();
  if (host === 'localhost' || host.endsWith('.localhost') || host.endsWith('.test') || host.endsWith('.local')) return false;
  if (/^127\./.test(host) || /^10\./.test(host) || /^192\.168\./.test(host) || /^172\.(1[6-9]|2\d|3[01])\./.test(host)) return false;
  if (host === '0.0.0.0' || host === '[::1]' || host === '::1') return false;
  return host.includes('.');
}

export function toTelegramHtml(input: string): string {
  const tokenRe = /<!--[\s\S]*?-->|<(\/?)([a-zA-Z][a-zA-Z0-9-]*)((?:\s[^<>]*?)?)\s*(\/?)>/g;
  const out: string[] = [];
  const stack: string[] = [];
  const listStack: Array<{ ordered: boolean; index: number }> = [];
  let dropDepth = 0;
  let last = 0;

  const pushText = (text: string): void => {
    if (dropDepth > 0 || text === '') return;
    out.push(escapeTextRun(text));
  };

  for (let match = tokenRe.exec(input); match !== null; match = tokenRe.exec(input)) {
    pushText(input.slice(last, match.index));
    last = tokenRe.lastIndex;
    if (match[0].startsWith('<!--')) continue;
    const closing = match[1] === '/';
    const name = (match[2] ?? '').toLowerCase();
    const attrs = match[3] ?? '';
    const selfClosing = match[4] === '/';

    if (DROP_CONTENT_TAGS.has(name)) {
      if (closing) dropDepth = Math.max(0, dropDepth - 1);
      else if (!selfClosing) dropDepth++;
      continue;
    }
    if (dropDepth > 0) continue;

    if (name === 'br') {
      out.push('\n');
      continue;
    }
    if (name === 'hr') {
      out.push('\n');
      continue;
    }
    if (name === 'ul' || name === 'ol') {
      if (closing) {
        listStack.pop();
        out.push('\n');
      } else {
        listStack.push({ ordered: name === 'ol', index: 0 });
        out.push('\n');
      }
      continue;
    }
    if (name === 'li') {
      if (closing) {
        out.push('\n');
      } else {
        const list = listStack[listStack.length - 1];
        const indent = '  '.repeat(Math.max(0, listStack.length - 1));
        if (list?.ordered) {
          list.index++;
          out.push(`${indent}${list.index}. `);
        } else {
          out.push(`${indent}• `);
        }
      }
      continue;
    }
    if (HEADING_TAGS.has(name)) {
      if (closing) {
        closeTag('b', stack, out);
        out.push('\n');
      } else {
        out.push('\n');
        stack.push('b');
        out.push('<b>');
      }
      continue;
    }
    if (BLOCK_TAGS.has(name)) {
      if (closing) out.push('\n\n');
      continue;
    }
    if (name === 'td' || name === 'th') {
      if (closing) out.push(' ');
      continue;
    }
    if (name === 'span' && /class\s*=\s*["']?tg-spoiler/i.test(attrs)) {
      if (!closing) {
        stack.push('tg-spoiler');
        out.push('<tg-spoiler>');
      }
      continue;
    }

    const mapped = TAG_MAP[name];
    if (!mapped) {
      if (name === 'span' && closing) closeTag('tg-spoiler', stack, out, true);
      continue;
    }
    if (closing) {
      closeTag(mapped, stack, out);
      continue;
    }
    if (selfClosing) continue;
    if (mapped === 'a') {
      const href = /href\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'>]+))/i.exec(attrs);
      const value = (href?.[1] ?? href?.[2] ?? href?.[3] ?? '').replace(/&amp;/g, '&');
      if (!value || !isSafeHref(value) || stack.includes('a')) {
        stack.push('a!');
        continue;
      }
      stack.push('a');
      out.push(`<a href="${escapeHtml(value)}">`);
      continue;
    }
    stack.push(mapped);
    out.push(`<${mapped}>`);
  }
  pushText(input.slice(last));
  while (stack.length > 0) {
    const tag = stack.pop();
    if (tag && !tag.endsWith('!')) out.push(`</${tag}>`);
  }
  return out
    .join('')
    .replace(/[ \t]+\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}

function closeTag(tag: string, stack: string[], out: string[], spanOnly = false): void {
  const target = tag === 'a' ? ['a', 'a!'] : [tag];
  let index = -1;
  for (let i = stack.length - 1; i >= 0; i--) {
    if (target.includes(stack[i] ?? '')) {
      index = i;
      break;
    }
  }
  if (index === -1) return;
  if (spanOnly && index !== stack.length - 1) return;
  while (stack.length > index) {
    const open = stack.pop();
    if (open && !open.endsWith('!')) out.push(`</${open}>`);
  }
}

export function markdownToTelegramHtml(markdown: string): string {
  const lines = escapeHtml(markdown).split(/\r?\n/);
  const html = lines
    .map((line) => {
      const heading = /^#{1,6}\s+(.*)$/.exec(line);
      if (heading) return `<b>${heading[1] ?? ''}</b>`;
      const bullet = /^\s*[-*+]\s+(.*)$/.exec(line);
      if (bullet) return `• ${bullet[1] ?? ''}`;
      return line;
    })
    .join('\n')
    .replace(/`([^`\n]+)`/g, '<code>$1</code>')
    .replace(/\*\*([^*\n]+)\*\*/g, '<b>$1</b>')
    .replace(/(^|[\s(])\*([^*\n]+)\*(?=$|[\s).,!?:;])/g, '$1<i>$2</i>')
    .replace(/(^|[\s(])_([^_\n]+)_(?=$|[\s).,!?:;])/g, '$1<i>$2</i>')
    .replace(/\[([^\]\n]+)\]\(((?:https?:\/\/|mailto:)[^)\s]+)\)/g, '<a href="$2">$1</a>');
  return toTelegramHtml(html);
}

export function stripHtml(html: string): string {
  return html
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<[^>]+>/g, '')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/&amp;/g, '&');
}

export function splitTelegramHtml(html: string, limit = TELEGRAM_MESSAGE_LIMIT): string[] {
  if (html.length <= limit) return [html];
  const parts: string[] = [];
  let current = '';
  const flush = (): void => {
    const balanced = toTelegramHtml(current);
    if (balanced !== '') parts.push(balanced);
    current = '';
  };
  for (const paragraph of html.split(/\n{2,}/)) {
    const candidate = current === '' ? paragraph : `${current}\n\n${paragraph}`;
    if (candidate.length <= limit - 64) {
      current = candidate;
      continue;
    }
    if (current !== '') flush();
    if (paragraph.length <= limit - 64) {
      current = paragraph;
      continue;
    }
    const plain = escapeHtml(stripHtml(paragraph));
    for (let offset = 0; offset < plain.length; offset += limit - 64) {
      parts.push(toTelegramHtml(plain.slice(offset, offset + limit - 64)));
    }
  }
  if (current !== '') flush();
  return parts;
}

export function buttonLabel(label: string, max = 48): string {
  const clean = label.replace(/\s+/g, ' ').trim();
  return clean.length <= max ? clean : `${clean.slice(0, max - 1)}…`;
}
