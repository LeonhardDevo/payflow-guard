import { useIntervalFn } from '@vueuse/core';
import type { MaybeRefOrGetter } from 'vue';
import { computed, ref, toValue } from 'vue';
import { t } from '@/lib/i18n';
import { formatRemaining, remainingUntil } from '@/lib/pf/guard';

export function useCountdown(
    target: MaybeRefOrGetter<string | null | undefined>,
) {
    const now = ref(Date.now());

    useIntervalFn(() => {
        now.value = Date.now();
    }, 1000);

    const remaining = computed(() =>
        remainingUntil(toValue(target), now.value),
    );
    const label = computed(() =>
        remaining.value ? formatRemaining(remaining.value, t) : null,
    );

    return { now, remaining, label };
}
