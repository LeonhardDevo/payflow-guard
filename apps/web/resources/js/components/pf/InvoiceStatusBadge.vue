<script setup lang="ts">
import { computed } from 'vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import { t } from '@/lib/i18n';
import { INVOICE_STATUS_TONE } from '@/lib/pf/status';
import type { InvoiceDisplayStatus } from '@/types/payflow';

const props = withDefaults(
    defineProps<{
        status: InvoiceDisplayStatus;
        partial?: boolean;
        daysOverdue?: number | null;
    }>(),
    { partial: false, daysOverdue: null },
);

const label = computed(() =>
    props.status === 'overdue' && props.daysOverdue
        ? t('pf.invoice.overdue_days', { days: props.daysOverdue })
        : t(`pf.status.${props.status}`),
);
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-1">
        <StatusBadge
            :tone="INVOICE_STATUS_TONE[status]"
            :label="label"
            :pulse="status === 'overdue'"
            data-test="invoice-status"
            :data-status="status"
        />
        <StatusBadge
            v-if="partial && status !== 'partially_paid'"
            tone="ai"
            :label="t('pf.status.partially_paid')"
        />
    </span>
</template>
