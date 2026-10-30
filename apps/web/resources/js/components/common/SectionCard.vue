<script setup lang="ts">
import type { Component } from 'vue';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        title?: string | null;
        description?: string | null;
        icon?: Component;
        tone?: 'default' | 'danger';
        flush?: boolean;
        class?: string;
    }>(),
    {
        title: null,
        description: null,
        icon: undefined,
        tone: 'default',
        flush: false,
        class: '',
    },
);
</script>

<template>
    <section
        :class="
            cn(
                'overflow-hidden rounded-2xl border bg-card text-card-foreground shadow-soft',
                tone === 'danger' ? 'border-destructive/35' : '',
                $props.class,
            )
        "
    >
        <header
            v-if="title || $slots.actions"
            class="flex flex-col gap-3 border-b px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6"
        >
            <div class="flex min-w-0 items-start gap-3">
                <span
                    v-if="icon"
                    :class="[
                        'mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-lg',
                        tone === 'danger'
                            ? 'bg-destructive-soft text-destructive-soft-foreground'
                            : 'bg-muted text-muted-foreground',
                    ]"
                    aria-hidden="true"
                >
                    <component :is="icon" class="size-4" />
                </span>
                <div class="min-w-0">
                    <h2
                        class="font-display text-base font-semibold tracking-tight"
                    >
                        {{ title }}
                    </h2>
                    <p
                        v-if="description"
                        class="mt-0.5 text-sm text-muted-foreground"
                    >
                        {{ description }}
                    </p>
                </div>
            </div>
            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </header>
        <div :class="flush ? '' : 'px-5 py-5 sm:px-6'">
            <slot />
        </div>
        <footer
            v-if="$slots.footer"
            class="flex flex-col-reverse gap-2 border-t bg-muted/40 px-5 py-3 sm:flex-row sm:items-center sm:justify-end sm:px-6"
        >
            <slot name="footer" />
        </footer>
    </section>
</template>
