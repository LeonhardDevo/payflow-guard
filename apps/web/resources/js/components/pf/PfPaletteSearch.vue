<script setup lang="ts">
import { Building2, FileText, HandCoins, Store } from '@lucide/vue';
import {
    injectListboxRootContext,
    ListboxGroup,
    ListboxGroupLabel,
} from 'reka-ui';
import type { Component } from 'vue';
import { computed, nextTick, watch } from 'vue';
import {
    CommandItem,
    provideCommandGroupContext,
    useCommand,
} from '@/components/ui/command';
import { Spinner } from '@/components/ui/spinner';
import { usePf } from '@/composables/pf/usePf';
import { usePfSearch } from '@/composables/pf/usePfSearch';
import { t } from '@/lib/i18n';
import { pfSearchHits } from '@/lib/pf/palette';
import type { PfPaletteHit } from '@/lib/pf/palette';

const props = withDefaults(defineProps<{ enabled?: boolean }>(), {
    enabled: true,
});

const emit = defineEmits<{ select: [href: string] }>();
const hasHits = defineModel<boolean>('hasHits', { default: false });

const pf = usePf();
const { filterState } = useCommand();
const listbox = injectListboxRootContext(null);

provideCommandGroupContext({});

const { results, resultsFor, loading } = usePfSearch(
    () => filterState.search,
    () => props.enabled,
);

const hits = computed(() =>
    pfSearchHits(
        results.value,
        (value) => pf.money(value),
        (value) => pf.label('status', value),
        t('pf.palette.untitled_invoice'),
    ),
);

watch(
    () => hits.value.length > 0,
    (value) => {
        hasHits.value = value;
    },
    { immediate: true },
);

watch(hits, async (value) => {
    if (!value.length || !listbox) {
        return;
    }

    await nextTick();

    if (!listbox.highlightedElement.value?.isConnected) {
        listbox.highlightFirstItem();
    }
});

const groups = computed(() => {
    const order: Array<{
        key: PfPaletteHit['group'];
        icon: Component;
    }> = [
        { key: 'invoices', icon: FileText },
        { key: 'clients', icon: Building2 },
        { key: 'vendors', icon: Store },
        { key: 'payouts', icon: HandCoins },
    ];

    return order
        .map((group) => ({
            ...group,
            heading: t(`pf.palette.groups.${group.key}`),
            items: hits.value.filter((hit) => hit.group === group.key),
        }))
        .filter((group) => group.items.length > 0);
});
</script>

<template>
    <div
        v-if="loading && !hits.length"
        class="flex items-center gap-2 px-3 py-2 text-sm text-muted-foreground"
        role="status"
        data-test="palette-searching"
    >
        <Spinner class="size-4" />
        {{ t('pf.palette.searching') }}
    </div>
    <ListboxGroup
        v-for="group in groups"
        :key="`${group.key}-${resultsFor}`"
        class="overflow-hidden p-1 text-foreground"
        :data-test="`palette-${group.key}`"
    >
        <ListboxGroupLabel
            class="px-2 py-1.5 text-xs font-medium text-muted-foreground"
        >
            {{ group.heading }}
        </ListboxGroupLabel>
        <CommandItem
            v-for="hit in group.items"
            :key="hit.key"
            :value="`search ${hit.label} ${hit.hint ?? ''} ${resultsFor ?? ''}`"
            :data-test="`palette-hit-${hit.key}`"
            @select="emit('select', hit.href)"
        >
            <component :is="group.icon" class="size-4" aria-hidden="true" />
            <span class="min-w-0 flex-1 truncate">{{ hit.label }}</span>
            <span
                v-if="hit.hint"
                class="shrink-0 text-xs text-muted-foreground tabular-nums"
                >{{ hit.hint }}</span
            >
        </CommandItem>
    </ListboxGroup>
</template>
