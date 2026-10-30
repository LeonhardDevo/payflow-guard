<script setup lang="ts">
import Reveal from '@/components/fx/Reveal.vue';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        eyebrow?: string | null;
        title: string;
        description?: string | null;
        align?: 'center' | 'left';
        id?: string;
    }>(),
    { eyebrow: null, description: null, align: 'center', id: undefined },
);
</script>

<template>
    <Reveal>
        <div
            :class="
                cn(
                    'max-w-3xl space-y-4',
                    align === 'center' ? 'mx-auto text-center' : '',
                )
            "
        >
            <p
                v-if="eyebrow"
                class="inline-flex items-center gap-2 rounded-full border border-primary/25 bg-primary-soft px-3 py-1 text-xs font-semibold tracking-wide text-primary-soft-foreground uppercase"
            >
                {{ eyebrow }}
            </p>
            <h2
                :id="id"
                class="font-display text-3xl font-extrabold tracking-tight text-balance sm:text-4xl"
            >
                {{ title }}
            </h2>
            <p
                v-if="description"
                class="text-base text-pretty text-muted-foreground sm:text-lg"
            >
                {{ description }}
            </p>
        </div>
    </Reveal>
</template>
