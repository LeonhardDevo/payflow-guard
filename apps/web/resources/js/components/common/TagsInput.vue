<script setup lang="ts">
import { X } from '@lucide/vue';
import { ref } from 'vue';
import { t } from '@/lib/i18n';

const props = withDefaults(
    defineProps<{
        id?: string;
        name?: string;
        placeholder?: string | null;
        max?: number;
        maxLength?: number;
    }>(),
    {
        id: undefined,
        name: undefined,
        placeholder: null,
        max: 12,
        maxLength: 40,
    },
);

const tags = defineModel<string[]>({ default: () => [] });
const draft = ref('');

function add(raw: string): void {
    const value = raw.trim().replace(/,+$/, '').slice(0, props.maxLength);

    if (!value || tags.value.length >= props.max) {
        return;
    }

    if (!tags.value.some((tag) => tag.toLowerCase() === value.toLowerCase())) {
        tags.value = [...tags.value, value];
    }
}

function remove(index: number): void {
    tags.value = tags.value.filter((_, i) => i !== index);
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' || event.key === ',') {
        event.preventDefault();
        add(draft.value);
        draft.value = '';
    } else if (
        event.key === 'Backspace' &&
        draft.value === '' &&
        tags.value.length
    ) {
        remove(tags.value.length - 1);
    }
}

function onBlur(): void {
    if (draft.value.trim()) {
        add(draft.value);
        draft.value = '';
    }
}

function onPaste(event: ClipboardEvent): void {
    const text = event.clipboardData?.getData('text') ?? '';

    if (text.includes(',')) {
        event.preventDefault();
        text.split(',').forEach(add);
    }
}
</script>

<template>
    <div
        class="flex min-h-9 w-full flex-wrap items-center gap-1.5 rounded-md border border-input bg-transparent px-2 py-1.5 shadow-xs transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 dark:bg-input/30"
    >
        <span
            v-for="(tag, index) in tags"
            :key="tag"
            class="inline-flex h-6 items-center gap-1 rounded-md bg-secondary pr-1 pl-2 text-xs font-medium text-secondary-foreground"
        >
            {{ tag }}
            <button
                type="button"
                class="inline-flex size-4 items-center justify-center rounded hover:bg-foreground/10 pointer-coarse:size-6"
                :aria-label="t('ui.tags.remove', { tag })"
                @click="remove(index)"
            >
                <X class="size-3" aria-hidden="true" />
            </button>
        </span>
        <input
            :id="id"
            v-model="draft"
            :name="name"
            type="text"
            class="min-w-24 flex-1 bg-transparent px-1 text-sm outline-none placeholder:text-muted-foreground pointer-coarse:h-9"
            :placeholder="
                tags.length ? '' : (placeholder ?? t('ui.tags.placeholder'))
            "
            :disabled="tags.length >= max"
            @keydown="onKeydown"
            @blur="onBlur"
            @paste="onPaste"
        />
    </div>
</template>
