<script setup lang="ts">
import type { Component, HTMLAttributes } from 'vue';
import SpotlightCard from '@/components/fx/SpotlightCard.vue';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        title: string;
        description: string;
        icon?: Component;
        span?: 2 | 3 | 4 | 6;
        rowSpan?: 1 | 2;
        tone?: 'primary' | 'ai' | 'warning' | 'info' | 'destructive';
        class?: HTMLAttributes['class'];
    }>(),
    { icon: undefined, span: 2, rowSpan: 1, tone: 'primary', class: '' },
);

const spanClass: Record<NonNullable<typeof props.span>, string> = {
    2: 'lg:col-span-2',
    3: 'lg:col-span-3',
    4: 'lg:col-span-4',
    6: 'md:col-span-2 lg:col-span-6',
};

const toneClass: Record<NonNullable<typeof props.tone>, string> = {
    primary: 'bg-primary-soft text-primary-soft-foreground',
    ai: 'bg-ai-soft text-ai-soft-foreground',
    warning: 'bg-warning-soft text-warning-soft-foreground',
    info: 'bg-info-soft text-info-soft-foreground',
    destructive: 'bg-destructive-soft text-destructive-soft-foreground',
};
</script>

<template>
    <SpotlightCard
        as="article"
        :class="
            cn(
                'flex flex-col',
                spanClass[props.span],
                props.rowSpan === 2 ? 'lg:row-span-2' : '',
                props.class,
            )
        "
    >
        <div class="flex h-full flex-col">
            <div
                v-if="$slots.visual"
                class="relative min-h-36 flex-1 overflow-hidden border-b border-border/60"
            >
                <slot name="visual" />
            </div>
            <div class="flex flex-col gap-2 p-6">
                <div class="flex items-center gap-3">
                    <span
                        v-if="icon"
                        :class="[
                            'inline-flex size-9 shrink-0 items-center justify-center rounded-xl',
                            toneClass[props.tone],
                        ]"
                    >
                        <component
                            :is="icon"
                            class="size-4.5"
                            aria-hidden="true"
                        />
                    </span>
                    <h3 class="text-base font-semibold tracking-tight">
                        {{ title }}
                    </h3>
                </div>
                <p class="text-sm leading-relaxed text-muted-foreground">
                    {{ description }}
                </p>
                <slot />
            </div>
        </div>
    </SpotlightCard>
</template>
