import type { ModelInfo, ModelsResponse } from '@/types/models';

export function normalizeModels(response: ModelsResponse | null | undefined): {
    models: ModelInfo[];
    source: 'live' | 'catalog' | null;
    error: string | null;
} {
    if (!response) {
        return { models: [], source: null, error: null };
    }

    const raw = Array.isArray(response) ? response : (response.models ?? []);
    const seen = new Set<string>();
    const models: ModelInfo[] = [];

    for (const entry of raw) {
        const model: ModelInfo | null =
            typeof entry === 'string'
                ? { id: entry }
                : entry && typeof entry.id === 'string'
                  ? entry
                  : null;

        if (model && model.id && !seen.has(model.id)) {
            seen.add(model.id);
            models.push(model);
        }
    }

    return {
        models,
        source: Array.isArray(response) ? null : (response.source ?? null),
        error: Array.isArray(response) ? null : (response.error ?? null),
    };
}
