<script setup lang="ts">
import type { EChartsCoreOption } from 'echarts/core';
import { computed, defineAsyncComponent } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useChartTheme } from '@/composables/useChartTheme';
import { useReducedMotion } from '@/composables/useReducedMotion';
import type { ChartTheme } from '@/lib/theme';

const props = withDefaults(
    defineProps<{
        build: (theme: ChartTheme) => EChartsCoreOption;
        label: string;
        height?: string;
        loading?: boolean;
    }>(),
    { height: '18rem', loading: false },
);

const emit = defineEmits<{ select: [index: number] }>();

const EChart = defineAsyncComponent({
    loader: () => import('./EChart.vue'),
    delay: 0,
});

const theme = useChartTheme();
const reduced = useReducedMotion();

const option = computed<EChartsCoreOption | null>(() => {
    if (!theme.value) {
        return null;
    }

    return {
        animation: !reduced.value,
        textStyle: {
            fontFamily: theme.value.fontFamily,
            color: theme.value.mutedText,
        },
        ...props.build(theme.value),
    };
});
</script>

<template>
    <figure class="relative w-full" :style="{ height }">
        <figcaption class="sr-only">{{ label }}</figcaption>
        <Skeleton
            v-if="loading || !option"
            class="absolute inset-0 rounded-xl"
        />
        <Suspense v-else>
            <EChart
                :option="option"
                class="size-full"
                role="img"
                :aria-label="label"
                @select="(index) => emit('select', index)"
            />
            <template #fallback>
                <Skeleton class="absolute inset-0 rounded-xl" />
            </template>
        </Suspense>
        <div v-if="$slots.default" class="sr-only">
            <slot />
        </div>
    </figure>
</template>
