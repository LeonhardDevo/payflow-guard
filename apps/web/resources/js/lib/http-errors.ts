import { HttpCancelledError, HttpResponseError } from '@inertiajs/core';

export type HttpErrorInfo = {
    kind: 'response' | 'cancelled' | 'network';
    status: number | null;
    message: string | null;
    body: Record<string, unknown> | null;
};

function parseBody(data: unknown): Record<string, unknown> | null {
    if (data && typeof data === 'object') {
        return data as Record<string, unknown>;
    }

    if (typeof data === 'string' && data.trim().startsWith('{')) {
        try {
            return JSON.parse(data) as Record<string, unknown>;
        } catch {
            return null;
        }
    }

    return null;
}

const GENERIC_STATUSES = new Set([404, 405, 419, 500]);

export function describeHttpError(error: unknown): HttpErrorInfo {
    if (error instanceof HttpCancelledError) {
        return { kind: 'cancelled', status: null, message: null, body: null };
    }

    if (error instanceof HttpResponseError) {
        const body = parseBody(error.response.data);
        const raw = body?.message ?? body?.detail ?? body?.error;

        return {
            kind: 'response',
            status: error.response.status,
            message:
                typeof raw === 'string' &&
                raw.trim() &&
                !GENERIC_STATUSES.has(error.response.status)
                    ? raw
                    : null,
            body,
        };
    }

    return { kind: 'network', status: null, message: null, body: null };
}
