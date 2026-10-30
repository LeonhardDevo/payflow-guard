<script setup lang="ts">
import { t } from '@/lib/i18n';
import { TONE_CLASS } from '@/lib/pf/status';
import { cn } from '@/lib/utils';
import type { Tone } from '@/types/payflow';

const props = withDefaults(defineProps<{ tone: Tone; class?: string }>(), {
    class: '',
});
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                TONE_CLASS[tone],
                props.class,
            )
        "
        data-test="tone-chip"
        :data-tone="tone"
        >{{ t(`pf.tone.${tone}`) }}</span
    >
</template>
