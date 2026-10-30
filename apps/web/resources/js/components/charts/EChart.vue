<script setup lang="ts">
import { BarChart, LineChart, PieChart } from 'echarts/charts';
import {
    DatasetComponent,
    GridComponent,
    LegendComponent,
    TooltipComponent,
} from 'echarts/components';
import type { EChartsCoreOption } from 'echarts/core';
import { use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import VChart from 'vue-echarts';

use([
    CanvasRenderer,
    LineChart,
    BarChart,
    PieChart,
    GridComponent,
    TooltipComponent,
    LegendComponent,
    DatasetComponent,
]);

defineProps<{ option: EChartsCoreOption }>();
const emit = defineEmits<{ select: [index: number] }>();

function onClick(params: { dataIndex?: number }): void {
    emit('select', params.dataIndex ?? 0);
}
</script>

<template>
    <VChart
        :option="option"
        autoresize
        :update-options="{ notMerge: true }"
        @click="onClick"
    />
</template>
