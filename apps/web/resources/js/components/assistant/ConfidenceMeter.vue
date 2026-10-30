<script setup lang="ts">
import { computed } from 'vue';
import { useFormat } from '@/composables/useFormat';
import { confidenceLevel } from '@/lib/citations';
import { t } from '@/lib/i18n';

const props = defineProps<{ value: number | null | undefined }>();

const fmt = useFormat();
const level = computed(() => confidenceLevel(props.value));
const known = computed(() => props.value !== null && props.value !== undefined);

const barClass = computed(() => {
    switch (level.value) {
        case 'high':
            return 'bg-success';
        case 'medium':
            return 'bg-warning';
        case 'low':
            return 'bg-destructive';
        default:
            return 'bg-muted-foreground';
    }
});
</script>

<template>
    <span
        class="inline-flex items-center gap-2 text-xs text-muted-foreground"
        :title="t('ui.assistant.confidence_hint')"
    >
        <span>{{ t('ui.assistant.confidence') }}</span>
        <span
            :role="known ? 'meter' : 'img'"
            :aria-label="
                known
                    ? t('ui.assistant.confidence')
                    : `${t('ui.assistant.confidence')}: ${t('ui.common.unknown')}`
            "
            :aria-valuenow="known ? (value ?? undefined) : undefined"
            :aria-valuemin="known ? 0 : undefined"
            :aria-valuemax="known ? 1 : undefined"
            :aria-valuetext="known ? fmt.percent(value) : undefined"
            class="relative h-1.5 w-14 overflow-hidden rounded-full bg-muted"
        >
            <span
                :class="[
                    'absolute inset-y-0 left-0 rounded-full transition-[width] duration-500',
                    barClass,
                ]"
                :style="{ width: `${Math.round((value ?? 0) * 100)}%` }"
            />
        </span>
        <span class="font-medium text-foreground tabular-nums">{{
            value !== null && value !== undefined ? fmt.percent(value) : '—'
        }}</span>
    </span>
</template>
