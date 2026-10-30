<script setup lang="ts">
import { Gauge } from '@lucide/vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import { t } from '@/lib/i18n';
import { RISK_TONE } from '@/lib/pf/status';
import type { RiskBand } from '@/types/payflow';

withDefaults(
    defineProps<{
        band: RiskBand;
        score?: number | null;
    }>(),
    { score: null },
);
</script>

<template>
    <StatusBadge
        :tone="RISK_TONE[band]"
        :icon="Gauge"
        :dot="false"
        :label="
            score === null
                ? t(`pf.risk.${band}`)
                : t('pf.risk.with_score', {
                      band: t(`pf.risk.${band}`),
                      score,
                  })
        "
        data-test="risk-badge"
        :data-band="band"
    />
</template>
