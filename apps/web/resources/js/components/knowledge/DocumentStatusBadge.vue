<script setup lang="ts">
import { CircleCheck, CircleX, Clock3 } from '@lucide/vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import { t } from '@/lib/i18n';
import type { KbDocumentStatus } from '@/types';

defineProps<{ status: KbDocumentStatus }>();
</script>

<template>
    <StatusBadge
        v-if="status === 'indexed'"
        data-test="kb-status"
        :data-status="status"
        tone="success"
        :icon="CircleCheck"
        :label="t('ui.knowledge.status.indexed')"
    />
    <StatusBadge
        v-else-if="status === 'processing'"
        data-test="kb-status"
        :data-status="status"
        tone="ai"
        pulse
        :label="t('ui.knowledge.status.processing')"
    />
    <StatusBadge
        v-else-if="status === 'queued'"
        data-test="kb-status"
        :data-status="status"
        tone="info"
        :icon="Clock3"
        :label="t('ui.knowledge.status.queued')"
    />
    <StatusBadge
        v-else
        data-test="kb-status"
        :data-status="status"
        tone="destructive"
        :icon="CircleX"
        :label="t('ui.knowledge.status.failed')"
    />
</template>
