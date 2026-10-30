<script setup lang="ts">
import StatusBadge from '@/components/common/StatusBadge.vue';
import { t } from '@/lib/i18n';
import { CLASS_TONE } from '@/lib/pf/status';
import type { LatePayerClass } from '@/types/payflow';

defineProps<{ value: LatePayerClass }>();
</script>

<template>
    <StatusBadge
        :tone="CLASS_TONE[value]"
        :label="t(`pf.class.${value}`)"
        data-test="client-class"
        :data-class="value"
    />
</template>
