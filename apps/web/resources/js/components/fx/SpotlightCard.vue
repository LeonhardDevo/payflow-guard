<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { computed, ref } from 'vue';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        class?: HTMLAttributes['class'];
        size?: number;
        color?: string;
        as?: string;
    }>(),
    {
        size: 260,
        color: 'color-mix(in oklch, var(--primary) 16%, transparent)',
        as: 'div',
        class: '',
    },
);

const x = ref(-1000);
const y = ref(-1000);

function onMove(event: PointerEvent): void {
    if (event.pointerType !== 'mouse') {
        return;
    }

    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    x.value = event.clientX - rect.left;
    y.value = event.clientY - rect.top;
}

function onLeave(): void {
    x.value = -1000;
    y.value = -1000;
}

const spotlight = computed(
    () =>
        `radial-gradient(${props.size}px circle at ${x.value}px ${y.value}px, ${props.color}, transparent 70%)`,
);
</script>

<template>
    <component
        :is="as"
        :class="
            cn(
                'group/spot relative overflow-hidden rounded-2xl border bg-card text-card-foreground shadow-soft',
                props.class,
            )
        "
        @pointermove="onMove"
        @pointerleave="onLeave"
    >
        <div
            aria-hidden="true"
            class="pointer-events-none absolute inset-0 opacity-0 transition-opacity duration-300 group-hover/spot:opacity-100 motion-reduce:hidden"
            :style="{ background: spotlight }"
        />
        <div class="relative h-full">
            <slot />
        </div>
    </component>
</template>
