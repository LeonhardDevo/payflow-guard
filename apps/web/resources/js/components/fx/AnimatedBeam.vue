<script setup lang="ts">
import { useId } from 'reka-ui';
import type { HTMLAttributes } from 'vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useReducedMotion } from '@/composables/useReducedMotion';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        containerRef: HTMLElement | null | undefined;
        fromRef: HTMLElement | null | undefined;
        toRef: HTMLElement | null | undefined;
        class?: HTMLAttributes['class'];
        curvature?: number;
        reverse?: boolean;
        pathColor?: string;
        pathWidth?: number;
        pathOpacity?: number;
        gradientStartColor?: string;
        gradientStopColor?: string;
        delay?: number;
        duration?: number;
        startYOffset?: number;
        endYOffset?: number;
    }>(),
    {
        class: '',
        curvature: 0,
        reverse: false,
        pathColor: 'var(--muted-foreground)',
        pathWidth: 2,
        pathOpacity: 0.2,
        gradientStartColor: 'var(--primary)',
        gradientStopColor: 'var(--ai)',
        delay: 0,
        duration: 5,
        startYOffset: 0,
        endYOffset: 0,
    },
);

const reduced = useReducedMotion();
const gradientId = `beam-${useId()}`;
const pathD = ref('');
const size = ref({ width: 0, height: 0 });
const rightToLeft = ref(false);

const x1 = computed(() =>
    (props.reverse ? !rightToLeft.value : rightToLeft.value)
        ? '90%; -10%;'
        : '10%; 110%;',
);
const x2 = computed(() =>
    (props.reverse ? !rightToLeft.value : rightToLeft.value)
        ? '100%; 0%;'
        : '0%; 100%;',
);

function updatePath(): void {
    const { containerRef, fromRef, toRef } = props;

    if (!containerRef || !fromRef || !toRef) {
        return;
    }

    const container = containerRef.getBoundingClientRect();
    const a = fromRef.getBoundingClientRect();
    const b = toRef.getBoundingClientRect();

    size.value = { width: container.width, height: container.height };

    const startX = a.left - container.left + a.width / 2;
    const startY = a.top - container.top + a.height / 2 + props.startYOffset;
    const endX = b.left - container.left + b.width / 2;
    const endY = b.top - container.top + b.height / 2 + props.endYOffset;

    rightToLeft.value = endX < startX;
    const controlY = startY - props.curvature;
    pathD.value = `M ${startX},${startY} Q ${(startX + endX) / 2},${controlY} ${endX},${endY}`;
}

let observer: ResizeObserver | null = null;

function observe(): void {
    observer?.disconnect();

    if (!props.containerRef) {
        return;
    }

    observer = new ResizeObserver(updatePath);
    observer.observe(props.containerRef);
    updatePath();
}

onMounted(observe);
watch(() => [props.containerRef, props.fromRef, props.toRef], observe);
onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <svg
        aria-hidden="true"
        fill="none"
        :width="size.width"
        :height="size.height"
        :viewBox="`0 0 ${size.width} ${size.height}`"
        :class="
            cn(
                'pointer-events-none absolute top-0 left-0 transform-gpu',
                props.class,
            )
        "
    >
        <path
            :d="pathD"
            :stroke="pathColor"
            :stroke-width="pathWidth"
            :stroke-opacity="pathOpacity"
            stroke-linecap="round"
        />
        <path
            v-if="!reduced"
            :d="pathD"
            :stroke-width="pathWidth"
            :stroke="`url(#${gradientId})`"
            stroke-opacity="1"
            stroke-linecap="round"
        />
        <defs>
            <linearGradient
                :id="gradientId"
                gradientUnits="userSpaceOnUse"
                x1="0%"
                x2="0%"
                y1="0%"
                y2="0%"
            >
                <stop :stop-color="gradientStartColor" stop-opacity="0" />
                <stop :stop-color="gradientStartColor" />
                <stop offset="32.5%" :stop-color="gradientStopColor" />
                <stop
                    offset="100%"
                    :stop-color="gradientStopColor"
                    stop-opacity="0"
                />
                <animate
                    attributeName="x1"
                    :values="x1"
                    :dur="`${duration}s`"
                    :begin="`${delay}s`"
                    keyTimes="0; 1"
                    keySplines="0.16 1 0.3 1"
                    calcMode="spline"
                    repeatCount="indefinite"
                />
                <animate
                    attributeName="x2"
                    :values="x2"
                    :dur="`${duration}s`"
                    :begin="`${delay}s`"
                    keyTimes="0; 1"
                    keySplines="0.16 1 0.3 1"
                    calcMode="spline"
                    repeatCount="indefinite"
                />
            </linearGradient>
        </defs>
    </svg>
</template>
