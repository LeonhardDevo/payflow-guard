import { useHttp } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import type { MaybeRefOrGetter } from 'vue';
import { ref, toValue, watch } from 'vue';
import { search } from '@/routes/pf';
import type { PfSearchResponse } from '@/types/payflow';

export const PF_SEARCH_MIN_CHARS = 2;

export function usePfSearch(
    query: MaybeRefOrGetter<string>,
    enabled: MaybeRefOrGetter<boolean> = true,
) {
    const http = useHttp<Record<string, never>, PfSearchResponse>({});
    const results = ref<PfSearchResponse | null>(null);
    const resultsFor = ref<string | null>(null);
    const loading = ref(false);
    const failed = ref(false);
    let sequence = 0;

    async function run(q: string): Promise<void> {
        const current = ++sequence;

        try {
            const response = await http.get(search({ query: { q } }).url);

            if (current === sequence) {
                results.value = response ?? null;
                resultsFor.value = q;
                failed.value = false;
            }
        } catch {
            if (current === sequence) {
                results.value = null;
                resultsFor.value = null;
                failed.value = true;
            }
        } finally {
            if (current === sequence) {
                loading.value = false;
            }
        }
    }

    const debounced = useDebounceFn(run, 200);

    watch(
        () => [toValue(query).trim(), toValue(enabled)] as const,
        ([q, on]) => {
            if (!on || q.length < PF_SEARCH_MIN_CHARS) {
                sequence++;
                http.cancel();
                results.value = null;
                resultsFor.value = null;
                loading.value = false;
                failed.value = false;

                return;
            }

            loading.value = true;
            void debounced(q);
        },
        { immediate: true },
    );

    return { results, resultsFor, loading, failed };
}
