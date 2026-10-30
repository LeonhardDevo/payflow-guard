import { useMounted, usePreferredReducedMotion } from '@vueuse/core';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';

export function useReducedMotion(): ComputedRef<boolean> {
    const preference = usePreferredReducedMotion();
    const mounted = useMounted();

    return computed(() => mounted.value && preference.value === 'reduce');
}
