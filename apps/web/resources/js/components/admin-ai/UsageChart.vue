<script setup lang="ts">
import ThemedChart from '@/components/charts/ThemedChart.vue';
import { useFormat } from '@/composables/useFormat';
import {
    axisTooltip,
    baseGrid,
    categoryAxis,
    legend,
    valueAxis,
} from '@/lib/charts';
import { t } from '@/lib/i18n';
import type { ChartTheme } from '@/lib/theme';
import { withAlpha } from '@/lib/theme';
import type { UsageDay } from '@/types';

const props = defineProps<{ days: UsageDay[] }>();

const fmt = useFormat();

function build(theme: ChartTheme) {
    const labels = props.days.map((day) => fmt.value.dayLabel(day.date));
    const [primary, ai, warning] = theme.series;

    return {
        grid: { ...baseGrid(), right: 12 },
        legend: legend(theme),
        tooltip: {
            ...axisTooltip(theme),
            valueFormatter: (value: unknown) =>
                typeof value === 'number'
                    ? fmt.value.number(value)
                    : String(value),
        },
        xAxis: categoryAxis(theme, labels),
        yAxis: [
            valueAxis(theme, (value: number) => fmt.value.compact(value)),
            {
                ...valueAxis(theme, (value: number) =>
                    fmt.value.money(value, 'USD', { compact: true }),
                ),
                splitLine: { show: false },
            },
        ],
        series: [
            {
                name: t('ui.admin_ai.prompt_tokens'),
                type: 'bar',
                stack: 'tokens',
                data: props.days.map((day) => day.prompt_tokens),
                itemStyle: { color: primary, borderRadius: [0, 0, 0, 0] },
                barMaxWidth: 22,
                emphasis: { focus: 'series' },
            },
            {
                name: t('ui.admin_ai.completion_tokens'),
                type: 'bar',
                stack: 'tokens',
                data: props.days.map((day) => day.completion_tokens),
                itemStyle: {
                    color: withAlpha(ai, 0.85),
                    borderRadius: [4, 4, 0, 0],
                },
                barMaxWidth: 22,
                emphasis: { focus: 'series' },
            },
            {
                name: t('ui.admin_ai.cost'),
                type: 'line',
                yAxisIndex: 1,
                smooth: true,
                symbol: 'circle',
                symbolSize: 6,
                data: props.days.map((day) => Number(day.cost_usd.toFixed(4))),
                lineStyle: { color: warning, width: 2 },
                itemStyle: { color: warning },
                tooltip: {
                    valueFormatter: (value: unknown) =>
                        typeof value === 'number'
                            ? fmt.value.money(value, 'USD', {
                                  fractionDigits: 4,
                              })
                            : String(value),
                },
            },
        ],
    };
}
</script>

<template>
    <ThemedChart
        :build="build"
        :label="t('ui.admin_ai.usage_chart_label')"
        height="20rem"
    >
        <table>
            <caption>
                {{
                    t('ui.admin_ai.usage_chart_label')
                }}
            </caption>
            <thead>
                <tr>
                    <th scope="col">{{ t('ui.admin_ai.date') }}</th>
                    <th scope="col">{{ t('ui.admin_ai.prompt_tokens') }}</th>
                    <th scope="col">
                        {{ t('ui.admin_ai.completion_tokens') }}
                    </th>
                    <th scope="col">{{ t('ui.admin_ai.cost') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="day in days" :key="day.date">
                    <td>{{ fmt.date(day.date) }}</td>
                    <td>{{ day.prompt_tokens }}</td>
                    <td>{{ day.completion_tokens }}</td>
                    <td>
                        {{
                            fmt.money(day.cost_usd, 'USD', {
                                fractionDigits: 4,
                            })
                        }}
                    </td>
                </tr>
            </tbody>
        </table>
    </ThemedChart>
</template>
