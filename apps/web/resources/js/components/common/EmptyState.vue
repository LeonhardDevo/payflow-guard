<script setup lang="ts">
import type { Component } from 'vue';
import EmptyIllustration from '@/components/brand/EmptyIllustration.vue';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        icon: Component;
        title: string;
        description?: string | null;
        tone?: 'primary' | 'ai' | 'warning' | 'info' | 'muted';
        compact?: boolean;
        class?: string;
    }>(),
    { description: null, tone: 'primary', compact: false, class: '' },
);
</script>

<template>
    <div
        :class="
            cn(
                'flex flex-col items-center justify-center text-center',
                compact ? 'gap-3 px-4 py-8' : 'gap-4 px-6 py-14',
                $props.class,
            )
        "
    >
        <EmptyIllustration
            :icon="icon"
            :tone="tone"
            :size="compact ? 'sm' : 'md'"
        />
        <div class="max-w-sm space-y-1.5">
            <h3 class="font-display text-base font-semibold tracking-tight">
                {{ title }}
            </h3>
            <p
                v-if="description"
                class="text-sm text-pretty text-muted-foreground"
            >
                {{ description }}
            </p>
        </div>
        <div v-if="$slots.default" class="flex flex-wrap justify-center gap-2">
            <slot />
        </div>
    </div>
</template>
