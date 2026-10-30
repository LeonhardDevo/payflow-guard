<script setup lang="ts">
import { Infinity as InfinityIcon } from '@lucide/vue';
import { computed } from 'vue';
import { useFormat } from '@/composables/useFormat';
import { usageState } from '@/lib/billing';
import { t } from '@/lib/i18n';
import type { UsageEntry } from '@/types/models';

const props = withDefaults(
    defineProps<{
        label: string;
        entry: UsageEntry;
        format?: (value: number) => string;
    }>(),
    { format: undefined },
);

const fmt = useFormat();
const state = computed(() => usageState(props.entry));
const show = (value: number) =>
    props.format ? props.format(value) : fmt.value.number(value);

const barClass = computed(() => {
    switch (state.value.level) {
        case 'critical':
            return 'bg-destructive';
        case 'warning':
            return 'bg-warning';
        default:
            return 'bg-primary';
    }
});
</script>

<template>
    <div class="space-y-2">
        <div class="flex items-baseline justify-between gap-3 text-sm">
            <span class="font-medium">{{ label }}</span>
            <span class="text-muted-foreground tabular-nums">
                <template v-if="state.level === 'unlimited'">
                    {{ show(entry.used) }}
                    <span class="inline-flex items-center gap-1">
                        /
                        <InfinityIcon class="size-3.5" aria-hidden="true" />
                        <span class="sr-only">{{
                            t('ui.billing.unlimited')
                        }}</span>
                    </span>
                </template>
                <template v-else>
                    {{ show(entry.used) }} / {{ show(entry.limit ?? 0) }}
                </template>
            </span>
        </div>
        <div
            role="meter"
            :aria-label="label"
            :aria-valuenow="entry.used"
            :aria-valuemin="0"
            :aria-valuemax="entry.limit ?? undefined"
            class="h-2 overflow-hidden rounded-full bg-muted"
        >
            <div
                v-if="state.ratio !== null"
                :class="[
                    'h-full rounded-full transition-[width] duration-700 ease-out',
                    barClass,
                ]"
                :style="{ width: `${Math.max(2, state.ratio * 100)}%` }"
            />
            <div
                v-else
                class="h-full w-full rounded-full bg-[repeating-linear-gradient(135deg,var(--primary-soft)_0_6px,transparent_6px_12px)]"
            />
        </div>
        <p
            v-if="state.level === 'critical' || state.level === 'warning'"
            :class="[
                'text-xs font-medium',
                state.level === 'critical'
                    ? 'text-destructive-soft-foreground'
                    : 'text-warning-soft-foreground',
            ]"
        >
            {{
                state.level === 'critical'
                    ? t('ui.billing.limit_reached')
                    : t('ui.billing.limit_near', {
                          remaining: show(state.remaining ?? 0),
                      })
            }}
        </p>
    </div>
</template>
