export type FileLike = { name: string; type: string; size: number };

export type UploadError =
    | { key: 'type'; params: { types: string } }
    | { key: 'size'; params: { max: number } }
    | { key: 'empty'; params: Record<string, never> };

export function extensionOf(name: string): string {
    const match = /\.([a-z0-9]+)$/i.exec(name.trim());

    return match ? match[1].toLowerCase() : '';
}

export function matchesAccept(
    file: Pick<FileLike, 'name' | 'type'>,
    accept: string[],
): boolean {
    if (!accept.length) {
        return true;
    }

    const extension = extensionOf(file.name);
    const mime = (file.type || '').toLowerCase();

    return accept.some((raw) => {
        const entry = raw.trim().toLowerCase();

        if (!entry) {
            return false;
        }

        if (entry.includes('/')) {
            return entry.endsWith('/*')
                ? mime.startsWith(entry.slice(0, -1))
                : mime === entry;
        }

        return extension === entry.replace(/^\./, '');
    });
}

export function acceptAttribute(accept: string[]): string {
    return accept
        .map((entry) => entry.trim())
        .filter(Boolean)
        .map((entry) =>
            entry.includes('/') || entry.startsWith('.') ? entry : `.${entry}`,
        )
        .join(',');
}

const MIME_LABELS: Record<string, string> = {
    'application/pdf': 'PDF',
    'application/msword': 'DOC',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
        'DOCX',
    'text/plain': 'TXT',
    'text/markdown': 'MD',
    'text/html': 'HTML',
    'text/csv': 'CSV',
    'image/png': 'PNG',
    'image/jpeg': 'JPG',
};

function mimeLabel(mime: string): string {
    if (MIME_LABELS[mime]) {
        return MIME_LABELS[mime];
    }

    const [type, subtype = ''] = mime.split('/');

    return (subtype === '*' || !subtype ? type : subtype).toUpperCase();
}

export function acceptLabel(accept: string[]): string {
    return accept
        .map((entry) => entry.trim().toLowerCase().replace(/^\./, ''))
        .filter(Boolean)
        .map((entry) =>
            entry.includes('/') ? mimeLabel(entry) : entry.toUpperCase(),
        )
        .filter((entry, index, all) => all.indexOf(entry) === index)
        .join(', ');
}

export function validateUpload(
    file: FileLike,
    accept: string[],
    maxSizeMb: number,
): UploadError | null {
    if (file.size === 0) {
        return { key: 'empty', params: {} };
    }

    if (!matchesAccept(file, accept)) {
        return { key: 'type', params: { types: acceptLabel(accept) } };
    }

    if (maxSizeMb > 0 && file.size > maxSizeMb * 1024 * 1024) {
        return { key: 'size', params: { max: maxSizeMb } };
    }

    return null;
}

export function titleFromFilename(name: string): string {
    const base = name.replace(/\.[a-z0-9]+$/i, '');

    return base
        .replace(/[_\-.]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

export type FileKind =
    | 'pdf'
    | 'doc'
    | 'sheet'
    | 'image'
    | 'text'
    | 'web'
    | 'other';

export function fileKind(mime: string, name: string): FileKind {
    const extension = extensionOf(name);
    const type = mime.toLowerCase();

    if (type === 'application/pdf' || extension === 'pdf') {
        return 'pdf';
    }

    if (type.startsWith('image/')) {
        return 'image';
    }

    if (
        ['doc', 'docx', 'odt', 'rtf'].includes(extension) ||
        type.includes('word')
    ) {
        return 'doc';
    }

    if (
        ['xls', 'xlsx', 'csv', 'ods'].includes(extension) ||
        type.includes('sheet') ||
        type === 'text/csv'
    ) {
        return 'sheet';
    }

    if (['html', 'htm'].includes(extension) || type === 'text/html') {
        return 'web';
    }

    if (
        ['txt', 'md', 'markdown'].includes(extension) ||
        type.startsWith('text/')
    ) {
        return 'text';
    }

    return 'other';
}
