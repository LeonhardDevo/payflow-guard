<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{
    title: string;
    description?: string | null;
    icon?: Component;
    eyebrow?: string | null;
}>();
</script>

<template>
    <header
        class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
    >
        <div class="flex min-w-0 items-start gap-3">
            <span
                v-if="icon"
                class="mt-0.5 hidden size-10 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary-soft-foreground ring-1 ring-primary/15 sm:inline-flex"
                aria-hidden="true"
            >
                <component :is="icon" class="size-5" />
            </span>
            <div class="min-w-0 space-y-1">
                <p
                    v-if="eyebrow"
                    class="text-xs font-semibold tracking-wider text-primary uppercase"
                >
                    {{ eyebrow }}
                </p>
                <h1
                    class="truncate font-display text-2xl font-bold tracking-tight text-balance"
                >
                    {{ title }}
                </h1>
                <p
                    v-if="description"
                    class="max-w-2xl text-sm text-pretty text-muted-foreground"
                >
                    {{ description }}
                </p>
                <div
                    v-if="$slots.meta"
                    class="flex flex-wrap items-center gap-2 pt-1"
                >
                    <slot name="meta" />
                </div>
            </div>
        </div>
        <div
            v-if="$slots.actions"
            class="flex shrink-0 flex-wrap items-center gap-2"
        >
            <slot name="actions" />
        </div>
    </header>
</template>
