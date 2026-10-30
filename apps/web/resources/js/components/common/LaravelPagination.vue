<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { useFormat } from '@/composables/useFormat';
import { t } from '@/lib/i18n';
import { pageItems, visibleRange } from '@/lib/pagination';
import { cn } from '@/lib/utils';
import type { PaginationLink, PaginationMeta } from '@/types/models';

const props = withDefaults(
    defineProps<{
        links: PaginationLink[];
        meta: PaginationMeta;
        count: number;
        only?: string[];
    }>(),
    { only: undefined },
);

const fmt = useFormat();
const parts = computed(() => pageItems(props.links));
const range = computed(() => visibleRange(props.meta, props.count));

const base =
    'inline-flex h-9 min-w-9 items-center justify-center gap-1 rounded-md px-2.5 text-sm font-medium transition-colors pointer-coarse:min-h-11 pointer-coarse:min-w-11';
</script>

<template>
    <div
        v-if="meta.total > 0"
        class="flex flex-col items-center justify-between gap-3 px-1 sm:flex-row"
    >
        <p class="text-sm text-muted-foreground tabular-nums">
            {{
                t('ui.pagination.showing', {
                    from: fmt.number(range.from),
                    to: fmt.number(range.to),
                    total: fmt.number(meta.total),
                })
            }}
        </p>
        <nav
            v-if="meta.last_page > 1"
            :aria-label="t('ui.pagination.label')"
            class="flex items-center gap-1"
        >
            <component
                :is="parts.previous ? Link : 'span'"
                :href="parts.previous ?? undefined"
                :only="only"
                preserve-scroll
                preserve-state
                :aria-disabled="!parts.previous || undefined"
                :class="
                    cn(
                        base,
                        parts.previous
                            ? 'hover:bg-accent hover:text-accent-foreground'
                            : 'pointer-events-none opacity-40',
                    )
                "
            >
                <ChevronLeft class="size-4" aria-hidden="true" />
                <span class="hidden sm:inline">{{
                    t('ui.pagination.previous')
                }}</span>
                <span class="sr-only sm:hidden">{{
                    t('ui.pagination.previous')
                }}</span>
            </component>

            <template
                v-for="item in parts.items"
                :key="item.type === 'gap' ? item.key : item.page"
            >
                <span
                    v-if="item.type === 'gap'"
                    class="hidden px-1 text-muted-foreground sm:inline"
                    aria-hidden="true"
                    >…</span
                >
                <Link
                    v-else-if="item.url && !item.active"
                    :href="item.url"
                    :only="only"
                    preserve-scroll
                    preserve-state
                    :class="
                        cn(
                            base,
                            'hidden hover:bg-accent hover:text-accent-foreground sm:inline-flex',
                        )
                    "
                    :aria-label="t('ui.pagination.page', { page: item.page })"
                >
                    {{ item.page }}
                </Link>
                <span
                    v-else
                    aria-current="page"
                    :class="cn(base, 'bg-primary text-primary-foreground')"
                >
                    {{ item.page }}
                </span>
            </template>

            <component
                :is="parts.next ? Link : 'span'"
                :href="parts.next ?? undefined"
                :only="only"
                preserve-scroll
                preserve-state
                :aria-disabled="!parts.next || undefined"
                :class="
                    cn(
                        base,
                        parts.next
                            ? 'hover:bg-accent hover:text-accent-foreground'
                            : 'pointer-events-none opacity-40',
                    )
                "
            >
                <span class="hidden sm:inline">{{
                    t('ui.pagination.next')
                }}</span>
                <span class="sr-only sm:hidden">{{
                    t('ui.pagination.next')
                }}</span>
                <ChevronRight class="size-4" aria-hidden="true" />
            </component>
        </nav>
    </div>
</template>
