<script setup lang="ts">
import type { Component } from 'vue';

withDefaults(
    defineProps<{
        icon: Component;
        tone?: 'primary' | 'ai' | 'warning' | 'info' | 'muted';
        size?: 'sm' | 'md';
    }>(),
    { tone: 'primary', size: 'md' },
);

const tones = {
    primary: 'bg-primary-soft text-primary-soft-foreground ring-primary/20',
    ai: 'bg-ai-soft text-ai-soft-foreground ring-ai/20',
    warning: 'bg-warning-soft text-warning-soft-foreground ring-warning/30',
    info: 'bg-info-soft text-info-soft-foreground ring-info/20',
    muted: 'bg-muted text-muted-foreground ring-border',
} as const;
</script>

<template>
    <div
        aria-hidden="true"
        :class="['relative mx-auto', size === 'sm' ? 'h-20 w-28' : 'h-28 w-40']"
    >
        <svg viewBox="0 0 160 112" class="absolute inset-0 size-full">
            <ellipse
                cx="80"
                cy="100"
                rx="58"
                ry="8"
                class="fill-foreground/5"
            />
            <g class="motion-safe:animate-float" style="animation-delay: -2s">
                <rect
                    x="34"
                    y="18"
                    width="92"
                    height="60"
                    rx="12"
                    class="fill-muted stroke-border"
                    stroke-width="1.5"
                    transform="rotate(-6 80 48)"
                />
            </g>
            <g class="motion-safe:animate-float">
                <rect
                    x="28"
                    y="26"
                    width="104"
                    height="66"
                    rx="14"
                    class="fill-card stroke-border"
                    stroke-width="1.5"
                />
                <rect
                    x="42"
                    y="42"
                    width="44"
                    height="7"
                    rx="3.5"
                    class="fill-foreground/15"
                />
                <rect
                    x="42"
                    y="56"
                    width="76"
                    height="6"
                    rx="3"
                    class="fill-foreground/8"
                />
                <rect
                    x="42"
                    y="68"
                    width="58"
                    height="6"
                    rx="3"
                    class="fill-foreground/8"
                />
                <circle cx="114" cy="45" r="5" class="fill-primary/60" />
            </g>
        </svg>
        <span
            :class="[
                'absolute -right-1 -bottom-1 inline-flex items-center justify-center rounded-2xl shadow-lifted ring-4',
                size === 'sm' ? 'size-9' : 'size-12',
                tones[tone],
            ]"
        >
            <component
                :is="icon"
                :class="size === 'sm' ? 'size-4' : 'size-5'"
            />
        </span>
    </div>
</template>
