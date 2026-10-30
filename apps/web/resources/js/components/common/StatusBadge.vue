<script setup lang="ts">
import type { Component } from 'vue';
import { cn } from '@/lib/utils';

export type StatusTone =
    | 'success'
    | 'warning'
    | 'destructive'
    | 'info'
    | 'ai'
    | 'neutral';

const props = withDefaults(
    defineProps<{
        tone?: StatusTone;
        label: string;
        icon?: Component;
        pulse?: boolean;
        dot?: boolean;
        class?: string;
    }>(),
    { tone: 'neutral', icon: undefined, pulse: false, dot: true, class: '' },
);

const toneClass: Record<StatusTone, string> = {
    success: 'bg-success-soft text-success-soft-foreground ring-success/25',
    warning: 'bg-warning-soft text-warning-soft-foreground ring-warning/35',
    destructive:
        'bg-destructive-soft text-destructive-soft-foreground ring-destructive/25',
    info: 'bg-info-soft text-info-soft-foreground ring-info/25',
    ai: 'bg-ai-soft text-ai-soft-foreground ring-ai/25',
    neutral: 'bg-muted text-muted-foreground ring-border',
};

const dotClass: Record<StatusTone, string> = {
    success: 'bg-success',
    warning: 'bg-warning',
    destructive: 'bg-destructive',
    info: 'bg-info',
    ai: 'bg-ai',
    neutral: 'bg-muted-foreground',
};
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex h-6 shrink-0 items-center gap-1.5 rounded-full px-2.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset',
                toneClass[props.tone],
                props.class,
            )
        "
    >
        <component :is="icon" v-if="icon" class="size-3.5" aria-hidden="true" />
        <span
            v-else-if="dot"
            aria-hidden="true"
            :class="[
                'relative inline-flex size-1.5 rounded-full',
                dotClass[props.tone],
            ]"
        >
            <span
                v-if="pulse"
                :class="[
                    'absolute inset-0 rounded-full opacity-75 motion-safe:animate-ping',
                    dotClass[props.tone],
                ]"
            />
        </span>
        {{ label }}
    </span>
</template>
