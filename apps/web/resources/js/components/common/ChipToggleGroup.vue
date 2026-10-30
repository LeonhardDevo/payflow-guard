<script setup lang="ts">
import { Check } from '@lucide/vue';
import type { SelectOption } from '@/types/models';

defineProps<{
    options: SelectOption[];
    label: string;
}>();

const selected = defineModel<string[]>({ default: () => [] });

function toggle(value: string): void {
    selected.value = selected.value.includes(value)
        ? selected.value.filter((item) => item !== value)
        : [...selected.value, value];
}
</script>

<template>
    <div role="group" :aria-label="label" class="flex flex-wrap gap-2">
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            :aria-pressed="selected.includes(option.value)"
            :class="[
                'inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-sm font-medium transition-colors pointer-coarse:h-11',
                selected.includes(option.value)
                    ? 'border-primary/40 bg-primary-soft text-primary-soft-foreground'
                    : 'border-input bg-transparent text-muted-foreground hover:bg-accent hover:text-accent-foreground',
            ]"
            @click="toggle(option.value)"
        >
            <Check
                v-if="selected.includes(option.value)"
                class="size-3.5"
                aria-hidden="true"
            />
            {{ option.label }}
        </button>
    </div>
</template>
