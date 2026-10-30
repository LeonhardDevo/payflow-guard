<script setup lang="ts">
import { computed } from 'vue';
import { useFormat } from '@/composables/useFormat';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { BillingInterval } from '@/types';

const props = withDefaults(defineProps<{ savings?: number }>(), { savings: 0 });
const interval = defineModel<BillingInterval>({ default: 'month' });

const fmt = useFormat();
const options = computed(() => [
    { value: 'month' as const, label: t('ui.billing.monthly') },
    { value: 'year' as const, label: t('ui.billing.yearly') },
]);

function onKey(event: KeyboardEvent): void {
    if (
        ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)
    ) {
        event.preventDefault();
        interval.value = interval.value === 'month' ? 'year' : 'month';
    }
}
</script>

<template>
    <div
        role="radiogroup"
        :aria-label="t('ui.billing.interval')"
        class="inline-flex shrink-0 items-center gap-1 rounded-full border bg-muted/60 p-1"
        @keydown="onKey"
    >
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            role="radio"
            :aria-checked="interval === option.value"
            :tabindex="interval === option.value ? 0 : -1"
            :class="
                cn(
                    'inline-flex h-8 cursor-pointer items-center gap-2 rounded-full px-4 text-sm font-medium whitespace-nowrap transition-all pointer-coarse:h-11',
                    interval === option.value
                        ? 'bg-card text-foreground shadow-soft'
                        : 'text-muted-foreground hover:text-foreground',
                )
            "
            @click="interval = option.value"
        >
            {{ option.label }}
            <span
                v-if="option.value === 'year' && props.savings > 0"
                class="rounded-full bg-primary-soft px-2 py-0.5 text-xs font-semibold whitespace-nowrap text-primary-soft-foreground"
            >
                {{
                    t('ui.billing.save', {
                        percent: fmt.percent(props.savings),
                    })
                }}
            </span>
        </button>
    </div>
</template>
