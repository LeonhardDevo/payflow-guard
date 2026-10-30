<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import WorkspaceOverview from '@/components/dashboard/WorkspaceOverview.vue';
import PfDashboard from '@/components/pf/dashboard/PfDashboard.vue';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import type { DashboardStat } from '@/types';
import type { DashboardProps } from '@/types/payflow';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'ui.nav.dashboard', href: endpoints.dashboard() },
        ],
    },
});

const props = defineProps<
    Partial<DashboardProps> & { stats?: DashboardStat[] }
>();
</script>

<template>
    <Head :title="t('ui.nav.dashboard')" />
    <PfDashboard v-if="props.kpis" v-bind="props as DashboardProps" />
    <WorkspaceOverview v-else :stats="props.stats ?? []" />
</template>
