<script setup lang="ts" generic="T extends string">
import { cn } from '@/lib/utils';

defineProps<{
    options: Array<{ value: T; label: string }>;
    label: string;
}>();

const model = defineModel<T>({ required: true });

function onKey(event: KeyboardEvent, options: Array<{ value: T }>): void {
    const index = options.findIndex((option) => option.value === model.value);
    const delta =
        event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;

    if (!delta) {
        return;
    }

    event.preventDefault();
    model.value =
        options[(index + delta + options.length) % options.length].value;
}
</script>

<template>
    <div
        role="radiogroup"
        :aria-label="label"
        class="inline-flex h-9 items-center gap-0.5 rounded-lg border bg-muted/60 p-0.5 pointer-coarse:h-11"
        @keydown="onKey($event, options)"
    >
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            role="radio"
            :aria-checked="model === option.value"
            :tabindex="model === option.value ? 0 : -1"
            :class="
                cn(
                    'inline-flex h-full items-center rounded-md px-3 text-sm font-medium whitespace-nowrap transition-colors',
                    model === option.value
                        ? 'bg-card text-foreground shadow-soft'
                        : 'text-muted-foreground hover:text-foreground',
                )
            "
            @click="model = option.value"
        >
            {{ option.label }}
        </button>
    </div>
</template>
