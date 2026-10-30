const MARKER = /\[(\d{1,2})\]/g;

export function linkCitationMarkers(
    html: string,
    citationCount: number,
    label: (n: number) => string,
): string {
    if (citationCount <= 0) {
        return html;
    }

    return html
        .split(/(<[^>]+>)/g)
        .map((part) => {
            if (part.startsWith('<')) {
                return part;
            }

            return part.replace(MARKER, (match, raw: string) => {
                const n = Number.parseInt(raw, 10);

                if (n < 1 || n > citationCount) {
                    return match;
                }

                const aria = escapeAttribute(label(n));

                return `<button type="button" class="cite-ref" data-cite="${n}" aria-label="${aria}">${n}</button>`;
            });
        })
        .join('');
}

export function referencedCitations(text: string): number[] {
    const seen: number[] = [];

    for (const match of text.matchAll(MARKER)) {
        const n = Number.parseInt(match[1] ?? '', 10);

        if (!seen.includes(n)) {
            seen.push(n);
        }
    }

    return seen;
}

export function markdownToPlain(markdown: string): string {
    return markdown
        .replace(/```[\s\S]*?```/g, ' ')
        .replace(/`([^`]+)`/g, '$1')
        .replace(/!\[[^\]]*\]\([^)]*\)/g, '')
        .replace(/\[([^\]]+)\]\((?:[^)]+)\)/g, '$1')
        .replace(/^#{1,6}\s+/gm, '')
        .replace(/^\s*[-*+]\s+/gm, '• ')
        .replace(/(\*\*|__)(.*?)\1/g, '$2')
        .replace(/(\*|_)(.*?)\1/g, '$2')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

export function escapeHtml(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function escapeAttribute(value: string): string {
    return escapeHtml(value);
}

export function plainToHtml(text: string): string {
    return text
        .split(/\n{2,}/)
        .map(
            (paragraph) =>
                `<p>${escapeHtml(paragraph).replace(/\n/g, '<br>')}</p>`,
        )
        .join('');
}

export function confidenceLevel(
    confidence: number | null | undefined,
): 'high' | 'medium' | 'low' | 'unknown' {
    if (
        confidence === null ||
        confidence === undefined ||
        Number.isNaN(confidence)
    ) {
        return 'unknown';
    }

    if (confidence >= 0.75) {
        return 'high';
    }

    return confidence >= 0.45 ? 'medium' : 'low';
}
