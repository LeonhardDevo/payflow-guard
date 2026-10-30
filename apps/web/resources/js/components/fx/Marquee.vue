<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        class?: HTMLAttributes['class'];
        reverse?: boolean;
        pauseOnHover?: boolean;
        vertical?: boolean;
        repeat?: number;
        duration?: string;
        gap?: string;
    }>(),
    {
        reverse: false,
        pauseOnHover: true,
        vertical: false,
        repeat: 3,
        duration: '40s',
        gap: '1rem',
    },
);
</script>

<template>
    <div
        :class="
            cn(
                'group flex gap-(--gap) overflow-hidden p-2',
                vertical ? 'flex-col' : 'mask-fade-x flex-row',
                $props.class,
            )
        "
        :style="{ '--duration': duration, '--gap': gap }"
    >
        <div
            v-for="index in repeat"
            :key="index"
            :aria-hidden="index > 1 ? 'true' : undefined"
            :class="
                cn(
                    'flex shrink-0 justify-around gap-(--gap) motion-reduce:animate-none',
                    vertical
                        ? 'animate-marquee-vertical flex-col'
                        : 'animate-marquee flex-row',
                    reverse ? '[animation-direction:reverse]' : '',
                    pauseOnHover
                        ? 'group-focus-within:[animation-play-state:paused] group-hover:[animation-play-state:paused]'
                        : '',
                )
            "
        >
            <slot />
        </div>
    </div>
</template>
