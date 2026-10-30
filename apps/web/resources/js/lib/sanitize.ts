import DOMPurify from 'dompurify';

const ALLOWED_TAGS = [
    'p',
    'br',
    'strong',
    'b',
    'em',
    'i',
    'u',
    's',
    'code',
    'pre',
    'blockquote',
    'ul',
    'ol',
    'li',
    'h1',
    'h2',
    'h3',
    'h4',
    'table',
    'thead',
    'tbody',
    'tr',
    'th',
    'td',
    'a',
    'hr',
    'span',
    'sup',
    'sub',
];

let hooked = false;

export function sanitizeAnswer(html: string): string {
    if (typeof window === 'undefined') {
        return '';
    }

    if (!hooked) {
        DOMPurify.addHook('afterSanitizeAttributes', (node) => {
            if (node.tagName === 'A') {
                node.setAttribute('target', '_blank');
                node.setAttribute('rel', 'noopener noreferrer nofollow');
            }
        });
        hooked = true;
    }

    return DOMPurify.sanitize(html, {
        ALLOWED_TAGS,
        ALLOWED_ATTR: ['href', 'title', 'colspan', 'rowspan'],
        ALLOWED_URI_REGEXP: /^(?:https?:|mailto:|\/)/i,
    });
}
