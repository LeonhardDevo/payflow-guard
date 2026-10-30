<script setup lang="ts">
import {
    TransitionPresets,
    useElementVisibility,
    useTransition,
} from '@vueuse/core';
import type { HTMLAttributes } from 'vue';
import { computed, onMounted, ref, watch } from 'vue';
import { useReducedMotion } from '@/composables/useReducedMotion';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        value: number;
        duration?: number;
        delay?: number;
        decimals?: number;
        format?: (value: number) => string;
        class?: HTMLAttributes['class'];
    }>(),
    { duration: 1200, delay: 0, decimals: 0, format: undefined, class: '' },
);

const reduced = useReducedMotion();
const el = ref<HTMLSpanElement>();
const visible = useElementVisibility(el, { threshold: 0 });
const target = ref(0);
const animate = ref(false);

const duration = computed(() => (reduced.value ? 0 : props.duration));
const delay = computed(() => (reduced.value ? 0 : props.delay));

const animated = useTransition(target, {
    duration,
    delay,
    transition: TransitionPresets.easeOutCubic,
});

const render = (value: number): string => {
    const rounded = Number(value.toFixed(props.decimals));

    return props.format
        ? props.format(rounded)
        : rounded.toLocaleString(undefined, {
              minimumFractionDigits: props.decimals,
              maximumFractionDigits: props.decimals,
          });
};

const finalValue = computed(() => render(props.value));
const display = computed(() =>
    animate.value && !reduced.value ? render(animated.value) : finalValue.value,
);

let started = false;

const inViewport = (node: HTMLElement): boolean => {
    const rect = node.getBoundingClientRect();

    return rect.bottom > 0 && rect.top < window.innerHeight;
};

onMounted(() => {
    if (reduced.value || !el.value || inViewport(el.value)) {
        return;
    }

    animate.value = true;
});

watch([visible, animate], ([isVisible, shouldAnimate]) => {
    if (shouldAnimate && isVisible && !started) {
        started = true;
        target.value = props.value;
    }
});

watch(
    () => props.value,
    (value) => {
        if (started) {
            target.value = value;
        }
    },
);
</script>

<template>
    <span ref="el" :class="cn('inline-block tabular-nums', props.class)">
        <span aria-hidden="true">{{ display }}</span>
        <span class="sr-only">{{ finalValue }}</span>
    </span>
</template>
