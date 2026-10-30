<script setup lang="ts">
import { Search, X } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { ref, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

const props = withDefaults(
    defineProps<{
        modelValue: string | null | undefined;
        placeholder?: string | null;
        label?: string | null;
        debounce?: number;
    }>(),
    { placeholder: null, label: null, debounce: 300 },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const draft = ref(props.modelValue ?? '');

watch(
    () => props.modelValue,
    (value) => {
        if ((value ?? '') !== draft.value) {
            draft.value = value ?? '';
        }
    },
);

watchDebounced(
    draft,
    (value) => {
        if (value !== (props.modelValue ?? '')) {
            emit('update:modelValue', value);
        }
    },
    { debounce: props.debounce },
);
</script>

<template>
    <div class="relative w-full sm:max-w-xs">
        <Search
            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
        />
        <Input
            v-model="draft"
            type="search"
            class="pr-9 pl-9"
            :aria-label="label ?? placeholder ?? t('ui.common.search')"
            :placeholder="placeholder ?? t('ui.common.search')"
        />
        <button
            v-if="draft"
            type="button"
            class="absolute top-1/2 right-1.5 inline-flex size-7 -translate-y-1/2 items-center justify-center rounded text-muted-foreground hover:bg-accent hover:text-foreground"
            :aria-label="t('ui.common.clear')"
            @click="draft = ''"
        >
            <X class="size-4" aria-hidden="true" />
        </button>
    </div>
</template>
