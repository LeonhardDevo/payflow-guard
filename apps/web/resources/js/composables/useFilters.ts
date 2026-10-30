import { router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import type { QueryValue } from '@/lib/endpoints';
import { withQuery } from '@/lib/endpoints';

export function useFilters<T extends Record<string, QueryValue>>(
    url: () => string,
    initial: T,
    only: string[],
) {
    const filters = reactive({ ...initial }) as T;
    let suspended = false;

    function apply(): void {
        router.visit(withQuery(url(), { ...filters }), {
            only,
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    watch(
        () => ({ ...filters }),
        () => {
            if (!suspended) {
                apply();
            }
        },
        { deep: true },
    );

    function reset(): void {
        suspended = true;

        for (const key of Object.keys(filters)) {
            (filters as Record<string, QueryValue>)[key] = null;
        }

        suspended = false;
        apply();
    }

    const active = () =>
        Object.values(filters).some(
            (value) => value !== null && value !== undefined && value !== '',
        );

    return { filters, reset, active, apply };
}
