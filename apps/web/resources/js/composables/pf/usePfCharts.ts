import type { MaybeRefOrGetter } from 'vue';
import { toValue } from 'vue';
import { useFormat } from '@/composables/useFormat';
import {
    axisTooltip,
    baseGrid,
    categoryAxis,
    legend,
    valueAxis,
} from '@/lib/charts';
import { t } from '@/lib/i18n';
import { agingColor, hatchDecal, prefersMoreContrast } from '@/lib/pf/charts';
import { toMajor } from '@/lib/pf/money';
import type { ChartTheme } from '@/lib/theme';
import type {
    AgingRow,
    CurrencyCode,
    ForecastWeek,
    Money,
} from '@/types/payflow';

export function usePfCharts(currency: MaybeRefOrGetter<CurrencyCode>) {
    const fmt = useFormat();
    const contrast = prefersMoreContrast();

    const major = (m: Money) => toMajor(m.minor, m.currency);
    const compactMoney = (value: number) =>
        fmt.value.money(value, toValue(currency), { compact: true });

    function aging(rows: AgingRow[]) {
        return (theme: ChartTheme) => ({
            grid: baseGrid(),
            tooltip: {
                ...axisTooltip(theme),
                valueFormatter: (v: number) => compactMoney(v),
            },
            xAxis: (() => {
                const axis = categoryAxis(
                    theme,
                    rows.map((row) => t(`pf.aging.${row.bucket}`)),
                );

                return {
                    ...axis,
                    axisLabel: {
                        ...axis.axisLabel,
                        interval: 0,
                        hideOverlap: false,
                        width: 52,
                        fontSize: 10,
                        overflow: 'break' as const,
                        lineHeight: 13,
                    },
                };
            })(),
            yAxis: valueAxis(theme, compactMoney),
            series: [
                {
                    type: 'bar',
                    name: t('pf.analytics.amount'),
                    barMaxWidth: 48,
                    label: {
                        show: true,
                        position: 'top',
                        color: theme.mutedText,
                        fontSize: 11,
                        formatter: (p: { value: number }) =>
                            compactMoney(p.value),
                    },
                    data: rows.map((row) => ({
                        value: major(row.amount),
                        itemStyle: {
                            color: agingColor(theme, row.bucket),
                            borderRadius: [6, 6, 0, 0],
                        },
                    })),
                },
            ],
        });
    }

    function forecast(weeks: ForecastWeek[]) {
        return (theme: ChartTheme) => {
            let running = 0;
            const cumulative = weeks.map((w) => {
                running += major(w.expected) + major(w.at_risk);

                return running;
            });

            return {
                grid: { ...baseGrid(), top: 40 },
                legend: legend(theme),
                tooltip: {
                    ...axisTooltip(theme),
                    formatter: (
                        items: Array<{
                            dataIndex: number;
                            seriesName: string;
                            value: number;
                            marker: string;
                        }>,
                    ) => {
                        const week = weeks[items[0]?.dataIndex ?? 0];

                        return [
                            `<strong>${week ? fmt.value.date(week.week_start) : ''}</strong>`,
                            ...items.map(
                                (i) =>
                                    `${i.marker} ${i.seriesName}: ${compactMoney(i.value)}`,
                            ),
                            week
                                ? t('pf.analytics.invoices_count', {
                                      count: week.invoices,
                                  })
                                : '',
                        ].join('<br>');
                    },
                },
                xAxis: categoryAxis(
                    theme,
                    weeks.map((w) => fmt.value.dayLabel(w.week_start)),
                ),
                yAxis: valueAxis(theme, compactMoney),
                series: [
                    {
                        type: 'bar',
                        stack: 'cash',
                        name: t('pf.analytics.expected'),
                        barMaxWidth: 28,
                        itemStyle: { color: theme.series[0] },
                        data: weeks.map((w) => major(w.expected)),
                    },
                    {
                        type: 'bar',
                        stack: 'cash',
                        name: t('pf.analytics.at_risk'),
                        barMaxWidth: 28,
                        itemStyle: {
                            color: theme.destructive,
                            borderRadius: [4, 4, 0, 0],
                            ...(contrast ? { decal: hatchDecal(theme) } : {}),
                        },
                        data: weeks.map((w) => major(w.at_risk)),
                    },
                    {
                        type: 'line',
                        name: t('pf.analytics.cumulative'),
                        symbol: 'none',
                        lineStyle: {
                            type: 'dashed',
                            width: 2,
                            color: theme.series[1],
                        },
                        itemStyle: { color: theme.series[1] },
                        data: cumulative,
                    },
                ],
            };
        };
    }

    return { aging, forecast, compactMoney, major };
}
