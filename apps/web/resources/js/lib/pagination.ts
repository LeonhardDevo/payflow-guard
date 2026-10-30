import type { PaginationLink, PaginationMeta } from '@/types/models';

export type PageItem =
    | { type: 'page'; page: number; url: string | null; active: boolean }
    | { type: 'gap'; key: string };

export function pageItems(links: PaginationLink[]): {
    previous: string | null;
    next: string | null;
    items: PageItem[];
} {
    if (links.length < 2) {
        return { previous: null, next: null, items: [] };
    }

    const previous = links[0]?.url ?? null;
    const next = links[links.length - 1]?.url ?? null;
    const items: PageItem[] = links.slice(1, -1).map((link, index) => {
        const page = Number.parseInt(link.label, 10);

        return Number.isNaN(page)
            ? { type: 'gap', key: `gap-${index}` }
            : { type: 'page', page, url: link.url, active: link.active };
    });

    return { previous, next, items };
}

export function visibleRange(
    meta: PaginationMeta,
    count: number,
): { from: number; to: number } {
    if (count === 0 || meta.total === 0) {
        return { from: 0, to: 0 };
    }

    const from = (meta.current_page - 1) * meta.per_page + 1;

    return { from, to: Math.min(meta.total, from + count - 1) };
}
