<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { computed } from 'vue';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        class?: HTMLAttributes['class'];
        size?: number;
        duration?: number;
        borderWidth?: number;
        colorFrom?: string;
        colorTo?: string;
        delay?: number;
    }>(),
    {
        size: 180,
        duration: 12,
        borderWidth: 1.5,
        colorFrom: 'var(--primary)',
        colorTo: 'var(--ai)',
        delay: 0,
        class: '',
    },
);

const style = computed(() => ({
    '--size': String(props.size),
    '--duration': `${props.duration}s`,
    '--border-width': String(props.borderWidth),
    '--color-from': props.colorFrom,
    '--color-to': props.colorTo,
    '--delay': `-${props.delay}s`,
}));
</script>

<template>
    <div
        aria-hidden="true"
        :style="style"
        :class="
            cn(
                'border-beam pointer-events-none absolute inset-0 rounded-[inherit] motion-reduce:hidden',
                props.class,
            )
        "
    />
</template>

<style scoped>
.border-beam {
    border: calc(var(--border-width) * 1px) solid transparent;
    mask:
        linear-gradient(transparent, transparent) padding-box,
        linear-gradient(white, white) border-box;
    mask-composite: intersect;
    -webkit-mask-composite: source-in;
}

.border-beam::after {
    content: '';
    position: absolute;
    aspect-ratio: 1;
    width: calc(var(--size) * 1px);
    background: linear-gradient(
        to left,
        var(--color-from),
        var(--color-to),
        transparent
    );
    offset-anchor: 90% 50%;
    offset-path: rect(0 auto auto 0 round calc(var(--size) * 1px));
    animation: border-beam-travel var(--duration) infinite linear;
    animation-delay: var(--delay);
}

@keyframes border-beam-travel {
    to {
        offset-distance: 100%;
    }
}
</style>
