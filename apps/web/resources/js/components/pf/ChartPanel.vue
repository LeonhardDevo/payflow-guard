<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Lock } from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/common/EmptyState.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import SegmentedControl from '@/components/common/SegmentedControl.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { billing } from '@/routes/settings';

const props = withDefaults(
    defineProps<{
        title: string;
        description?: string | null;
        icon?: Component;
        locked?: boolean;
        empty?: boolean;
        emptyTitle?: string | null;
        emptyIcon?: Component;
        table?: boolean;
    }>(),
    {
        description: null,
        icon: undefined,
        locked: false,
        empty: false,
        emptyTitle: null,
        emptyIcon: undefined,
        table: true,
    },
);

const view = ref<'chart' | 'table'>('chart');
const options = computed(() => [
    { value: 'chart' as const, label: t('pf.common.chart') },
    { value: 'table' as const, label: t('pf.common.table') },
]);
const showToggle = computed(() => props.table && !props.locked && !props.empty);
</script>

<template>
    <SectionCard flush class="@container">
        <header
            class="flex flex-col gap-3 border-b px-5 py-4 sm:px-6 @lg:flex-row @lg:items-start @lg:justify-between"
        >
            <div class="flex min-w-0 items-start gap-3">
                <span
                    v-if="icon"
                    class="mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
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
            <div
                v-if="showToggle || $slots.actions"
                class="flex shrink-0 flex-wrap items-center gap-2"
            >
                <slot name="actions" />
                <SegmentedControl
                    v-if="showToggle"
                    v-model="view"
                    :options="options"
                    :label="t('pf.common.view')"
                />
            </div>
        </header>

        <div class="px-5 py-5 sm:px-6">
            <div
                v-if="locked"
                class="relative overflow-hidden rounded-xl"
                data-test="chart-locked"
            >
                <div
                    class="pointer-events-none flex h-48 items-end gap-2 px-4 pb-4 opacity-60 blur-sm select-none"
                    aria-hidden="true"
                >
                    <div
                        v-for="h in [40, 65, 50, 80, 55, 70, 45, 60]"
                        :key="h"
                        class="flex-1 rounded-t-md bg-muted-foreground/30"
                        :style="{ height: `${h}%` }"
                    />
                </div>
                <div
                    class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-background/60 p-4 text-center backdrop-blur-[1px]"
                >
                    <span
                        class="inline-flex size-10 items-center justify-center rounded-full bg-ai-soft text-ai-soft-foreground"
                    >
                        <Lock class="size-5" aria-hidden="true" />
                    </span>
                    <p class="max-w-xs text-sm text-muted-foreground">
                        {{ t('pf.analytics.locked') }}
                    </p>
                    <Button size="sm" as-child>
                        <Link :href="billing().url">{{
                            t('pf.analytics.upgrade')
                        }}</Link>
                    </Button>
                </div>
            </div>
            <EmptyState
                v-else-if="empty"
                compact
                :icon="emptyIcon ?? icon ?? Lock"
                tone="muted"
                :title="emptyTitle ?? t('pf.analytics.no_data')"
            />
            <template v-else>
                <div v-show="view === 'chart'">
                    <slot name="chart" />
                </div>
                <div v-if="view === 'table'" class="overflow-x-auto">
                    <slot name="table" />
                </div>
            </template>
        </div>

        <footer
            v-if="$slots.footer"
            class="flex flex-col-reverse gap-2 border-t bg-muted/40 px-5 py-3 sm:flex-row sm:items-center sm:justify-end sm:px-6"
        >
            <slot name="footer" />
        </footer>
    </SectionCard>
</template>
