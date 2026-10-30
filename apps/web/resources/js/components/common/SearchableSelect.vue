<script setup lang="ts">
import { Check, ChevronsUpDown, CornerDownLeft, Search } from '@lucide/vue';
import { useId } from 'reka-ui';
import { computed, nextTick, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type SearchableOption = {
    value: string;
    label: string;
    hint?: string | null;
};

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        options: SearchableOption[];
        id?: string;
        name?: string;
        placeholder?: string | null;
        searchPlaceholder?: string | null;
        allowCustom?: boolean;
        loading?: boolean;
        disabled?: boolean;
        invalid?: boolean;
        maxVisible?: number;
        clearable?: boolean;
    }>(),
    {
        id: undefined,
        name: undefined,
        placeholder: null,
        searchPlaceholder: null,
        allowCustom: false,
        loading: false,
        disabled: false,
        invalid: false,
        maxVisible: 150,
        clearable: false,
    },
);

const model = defineModel<string | null>({ default: null });

const open = ref(false);
const query = defineModel<string>('query', { default: '' });
const active = ref(0);
const listId = `ss-list-${useId()}`;
const searchInput = ref<HTMLInputElement | null>(null);

const selectedLabel = computed(() => {
    if (!model.value) {
        return null;
    }

    return (
        props.options.find((option) => option.value === model.value)?.label ??
        model.value
    );
});

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    const list = q
        ? props.options.filter(
              (option) =>
                  option.label.toLowerCase().includes(q) ||
                  option.value.toLowerCase().includes(q) ||
                  (option.hint ?? '').toLowerCase().includes(q),
          )
        : props.options;

    return list.slice(0, props.maxVisible);
});

const customValue = computed(() => {
    const q = query.value.trim();

    if (!props.allowCustom || !q) {
        return null;
    }

    return props.options.some(
        (option) => option.value.toLowerCase() === q.toLowerCase(),
    )
        ? null
        : q;
});

const total = computed(
    () => filtered.value.length + (customValue.value ? 1 : 0),
);

watch(open, async (value) => {
    if (value) {
        query.value = '';
        const index = filtered.value.findIndex(
            (option) => option.value === model.value,
        );
        active.value = Math.max(0, index);
        await nextTick();
        searchInput.value?.focus();
        scrollActive();
    }
});

watch(query, () => {
    active.value = 0;
});

function select(value: string | null): void {
    model.value = value;
    open.value = false;
}

function scrollActive(): void {
    void nextTick(() => {
        document
            .getElementById(`${listId}-${active.value}`)
            ?.scrollIntoView({ block: 'nearest' });
    });
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = total.value ? (active.value + 1) % total.value : 0;
        scrollActive();
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = total.value
            ? (active.value - 1 + total.value) % total.value
            : 0;
        scrollActive();
    } else if (event.key === 'Enter') {
        event.preventDefault();

        if (customValue.value && active.value === filtered.value.length) {
            select(customValue.value);
        } else if (filtered.value[active.value]) {
            select(filtered.value[active.value].value);
        } else if (customValue.value) {
            select(customValue.value);
        }
    }
}
</script>

<template>
    <input v-if="name" type="hidden" :name="name" :value="model ?? ''" />
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                v-bind="$attrs"
                :id="id"
                type="button"
                variant="outline"
                role="combobox"
                aria-haspopup="listbox"
                :aria-expanded="open"
                :aria-controls="listId"
                :aria-invalid="invalid || undefined"
                :disabled="disabled"
                :class="
                    cn(
                        'w-full justify-between bg-transparent px-3 font-normal shadow-xs dark:bg-input/30',
                        !selectedLabel ? 'text-muted-foreground' : '',
                    )
                "
            >
                <span class="truncate">{{
                    selectedLabel ??
                    placeholder ??
                    t('ui.common.select_placeholder')
                }}</span>
                <Spinner v-if="loading" class="size-4" />
                <ChevronsUpDown
                    v-else
                    class="size-4 opacity-50"
                    aria-hidden="true"
                />
            </Button>
        </PopoverTrigger>
        <PopoverContent
            align="start"
            class="w-(--reka-popover-trigger-width) min-w-64 p-0"
            @open-auto-focus.prevent
        >
            <div class="flex items-center gap-2 border-b px-3">
                <Search class="size-4 shrink-0 opacity-50" aria-hidden="true" />
                <input
                    ref="searchInput"
                    v-model="query"
                    type="text"
                    role="searchbox"
                    autocomplete="off"
                    :aria-controls="listId"
                    :aria-activedescendant="
                        total ? `${listId}-${active}` : undefined
                    "
                    :aria-label="searchPlaceholder ?? t('ui.common.search')"
                    :placeholder="searchPlaceholder ?? t('ui.common.search')"
                    class="h-10 w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                    @keydown="onKeydown"
                />
            </div>
            <ul
                :id="listId"
                role="listbox"
                class="max-h-72 scrollbar-thin overflow-y-auto p-1"
                :aria-label="placeholder ?? t('ui.common.select_placeholder')"
            >
                <li
                    v-for="(option, index) in filtered"
                    :id="`${listId}-${index}`"
                    :key="option.value"
                    role="option"
                    :aria-selected="option.value === model"
                    :class="
                        cn(
                            'flex cursor-pointer items-center gap-2 rounded-md px-2 py-2 text-sm pointer-coarse:py-3',
                            index === active
                                ? 'bg-accent text-accent-foreground'
                                : '',
                        )
                    "
                    @click="select(option.value)"
                    @mousemove="active = index"
                >
                    <Check
                        :class="[
                            'size-4 shrink-0',
                            option.value === model
                                ? 'text-primary opacity-100'
                                : 'opacity-0',
                        ]"
                        aria-hidden="true"
                    />
                    <span class="min-w-0 flex-1 truncate">{{
                        option.label
                    }}</span>
                    <span
                        v-if="option.hint"
                        class="shrink-0 font-mono text-xs text-muted-foreground"
                        >{{ option.hint }}</span
                    >
                </li>
                <li
                    v-if="customValue"
                    :id="`${listId}-${filtered.length}`"
                    role="option"
                    :aria-selected="false"
                    :class="
                        cn(
                            'flex cursor-pointer items-center gap-2 rounded-md px-2 py-2 text-sm',
                            active === filtered.length
                                ? 'bg-accent text-accent-foreground'
                                : '',
                        )
                    "
                    @click="select(customValue)"
                    @mousemove="active = filtered.length"
                >
                    <CornerDownLeft
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span class="truncate">{{
                        t('ui.common.use_custom', { value: customValue })
                    }}</span>
                </li>
                <li
                    v-if="!filtered.length && !customValue"
                    class="px-2 py-6 text-center text-sm text-muted-foreground"
                    role="presentation"
                >
                    {{ t('ui.common.no_results') }}
                </li>
            </ul>
            <div v-if="clearable && model" class="border-t p-1">
                <button
                    type="button"
                    class="w-full rounded-md px-2 py-1.5 text-left text-sm text-muted-foreground hover:bg-accent hover:text-accent-foreground"
                    @click="select(null)"
                >
                    {{ t('ui.common.clear_selection') }}
                </button>
            </div>
        </PopoverContent>
    </Popover>
</template>
