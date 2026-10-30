<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    BellRing,
    CalendarClock,
    ChartColumn,
    ChartLine,
    ChartNoAxesColumn,
    Coins,
    Gauge,
    HandCoins,
    Hourglass,
    TrendingUp,
    UsersRound,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import ThemedChart from '@/components/charts/ThemedChart.vue';
import KpiCard from '@/components/common/KpiCard.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SegmentedControl from '@/components/common/SegmentedControl.vue';
import ChartPanel from '@/components/pf/ChartPanel.vue';
import ClientClassBadge from '@/components/pf/ClientClassBadge.vue';
import ToneChip from '@/components/pf/ToneChip.vue';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useFormat } from '@/composables/useFormat';
import { usePfCharts } from '@/composables/pf/usePfCharts';
import { usePf } from '@/composables/pf/usePf';
import {
    areaFill,
    axisTooltip,
    baseGrid,
    categoryAxis,
    legend,
    valueAxis,
} from '@/lib/charts';
import { t } from '@/lib/i18n';
import {
    hatchDecal,
    prefersMoreContrast,
    riskColor,
    toneColor,
} from '@/lib/pf/charts';
import { toMajor } from '@/lib/pf/money';
import type { ChartTheme } from '@/lib/theme';
import { index } from '@/routes/analytics';
import { show as showClient } from '@/routes/clients';
import { index as invoicesIndex } from '@/routes/invoices';
import type { AnalyticsProps, Money } from '@/types/payflow';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'pf.nav.analytics', href: index() }],
    },
});

const props = defineProps<AnalyticsProps>();
const pf = usePf();
const fmt = useFormat();

function reload(query: { currency?: string; months?: number }): void {
    router.get(
        index({
            query: { currency: props.currency, months: props.months, ...query },
        }).url,
        {},
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

const monthsModel = computed({
    get: () => String(props.months) as '3' | '6' | '12',
    set: (value: '3' | '6' | '12') => reload({ months: Number(value) }),
});
const monthOptions = computed(() =>
    (['3', '6', '12'] as const).map((value) => ({
        value,
        label: t('pf.analytics.months', { count: value }),
    })),
);
const currencyModel = computed({
    get: () => props.currency,
    set: (value: string) => reload({ currency: value }),
});

const major = (m: Money) => toMajor(m.minor, m.currency);
const compactMoney = (value: number) =>
    fmt.value.money(value, props.currency, { compact: true });

const contrast = prefersMoreContrast();

const k = computed(() => props.kpis);

const monthLabel = (month: string) => {
    const date = new Date(`${month}-01T00:00:00Z`);

    return Number.isNaN(date.getTime())
        ? month
        : new Intl.DateTimeFormat(fmt.value.intlLocale, {
              month: 'short',
              year: '2-digit',
              timeZone: 'UTC',
          }).format(date);
};

function openAging(i: number): void {
    const row = props.aging[i];

    if (row) {
        router.visit(
            invoicesIndex({
                query: { aging: row.bucket, currency: props.currency },
            }).url,
        );
    }
}

function buildMonthly(theme: ChartTheme) {
    return {
        grid: { ...baseGrid(), top: 40 },
        legend: legend(theme),
        tooltip: axisTooltip(theme),
        xAxis: categoryAxis(
            theme,
            props.monthly.map((p) => monthLabel(p.month)),
        ),
        yAxis: [
            valueAxis(theme, compactMoney),
            {
                ...valueAxis(theme, (v: number) => fmt.value.percent(v)),
                max: 1,
                min: 0,
                splitLine: { show: false },
            },
        ],
        series: [
            {
                type: 'bar',
                name: t('pf.analytics.collected'),
                barMaxWidth: 22,
                itemStyle: {
                    color: theme.series[0],
                    borderRadius: [4, 4, 0, 0],
                },
                tooltip: { valueFormatter: (v: number) => compactMoney(v) },
                data: props.monthly.map((p) => major(p.collected)),
            },
            {
                type: 'bar',
                name: t('pf.analytics.invoiced'),
                barMaxWidth: 22,
                itemStyle: {
                    color: theme.series[1],
                    borderRadius: [4, 4, 0, 0],
                },
                tooltip: { valueFormatter: (v: number) => compactMoney(v) },
                data: props.monthly.map((p) => major(p.invoiced)),
            },
            {
                type: 'line',
                name: t('pf.analytics.on_time_rate'),
                yAxisIndex: 1,
                smooth: true,
                symbolSize: 6,
                itemStyle: { color: theme.series[2] },
                tooltip: {
                    valueFormatter: (v: number | null) =>
                        v === null ? '—' : fmt.value.percent(v),
                },
                data: props.monthly.map((p) => p.on_time_rate),
            },
        ],
    };
}

function buildDso(theme: ChartTheme) {
    return {
        grid: baseGrid(),
        tooltip: {
            ...axisTooltip(theme),
            valueFormatter: (v: number | null) => pf.days(v),
        },
        xAxis: {
            ...categoryAxis(
                theme,
                props.dso_trend.map((p) => fmt.value.dayLabel(p.date)),
            ),
            boundaryGap: false,
        },
        yAxis: valueAxis(theme, (v: number) =>
            t('pf.client.days_short', { days: pf.num(v) }),
        ),
        series: [
            {
                type: 'line',
                name: t('pf.analytics.dso'),
                smooth: true,
                symbolSize: 6,
                itemStyle: { color: theme.primary },
                areaStyle: areaFill(theme.primary),
                data: props.dso_trend.map((p) => p.days),
            },
        ],
    };
}

const forecastWeeks = computed(() => props.forecast?.weeks ?? []);
const charts = usePfCharts(() => props.currency);
const buildAging = computed(() => charts.aging(props.aging));
const buildForecast = computed(() => charts.forecast(forecastWeeks.value));

const latePayers = computed(() => [...props.late_payers].reverse());

function buildLatePayers(theme: ChartTheme) {
    return {
        grid: { ...baseGrid(), left: 8, right: 24 },
        tooltip: {
            ...axisTooltip(theme),
            axisPointer: { type: 'shadow' },
            valueFormatter: (v: number) => pf.days(v),
        },
        xAxis: valueAxis(theme, (v: number) =>
            t('pf.client.days_short', { days: pf.num(v) }),
        ),
        yAxis: {
            ...categoryAxis(
                theme,
                latePayers.value.map((r) => r.client.name),
            ),
            axisLabel: {
                color: theme.mutedText,
                fontSize: 11,
                width: 120,
                overflow: 'truncate',
            },
        },
        series: [
            {
                type: 'bar',
                name: t('pf.client.avg_days_late'),
                barMaxWidth: 18,
                data: latePayers.value.map((r) => ({
                    value: r.avg_days_late,
                    itemStyle: {
                        color:
                            r.client.late_payer_class === 'chronic'
                                ? theme.destructive
                                : r.client.late_payer_class === 'occasional'
                                  ? theme.warning
                                  : theme.primary,
                        borderRadius: [0, 6, 6, 0],
                    },
                })),
            },
        ],
    };
}

function openLatePayer(i: number): void {
    const row = latePayers.value[i];

    if (row) {
        router.visit(showClient(row.client.id).url);
    }
}

const riskTotal = computed(() =>
    (props.risk_mix ?? []).reduce((sum, r) => sum + r.amount.minor, 0),
);

function buildRiskMix(theme: ChartTheme) {
    const total = riskTotal.value || 1;

    return {
        grid: { left: 0, right: 0, top: 28, bottom: 0, containLabel: false },
        legend: legend(theme),
        tooltip: {
            trigger: 'item',
            backgroundColor: theme.tooltipBackground,
            borderColor: theme.tooltipBorder,
            textStyle: { color: theme.text },
            valueFormatter: (v: number) => fmt.value.percent(v / 100),
        },
        xAxis: { type: 'value', max: 100, show: false },
        yAxis: { type: 'category', data: [''], show: false },
        series: (props.risk_mix ?? []).map((row) => ({
            type: 'bar',
            stack: 'mix',
            name: t(`pf.risk.${row.band}`),
            barWidth: 28,
            itemStyle: {
                color: riskColor(theme, row.band),
                ...(contrast && row.band === 'high'
                    ? { decal: hatchDecal(theme) }
                    : {}),
            },
            data: [Math.round((row.amount.minor / total) * 1000) / 10],
        })),
    };
}

function buildTone(theme: ChartTheme) {
    const rows = props.tone_effectiveness ?? [];

    return {
        grid: baseGrid(),
        tooltip: {
            ...axisTooltip(theme),
            axisPointer: { type: 'shadow' },
            formatter: (items: Array<{ dataIndex: number }>) => {
                const row = rows[items[0]?.dataIndex ?? 0];

                return row
                    ? `<strong>${t(`pf.tone.${row.tone}`)}</strong><br>${t('pf.analytics.tone_tooltip', { paid: row.paid_within_7d, sent: row.sent })}`
                    : '';
            },
        },
        xAxis: categoryAxis(
            theme,
            rows.map((r) => t(`pf.tone.${r.tone}`)),
        ),
        yAxis: {
            ...valueAxis(theme, (v: number) => fmt.value.percent(v)),
            max: 1,
        },
        series: [
            {
                type: 'bar',
                barMaxWidth: 40,
                label: {
                    show: true,
                    position: 'top',
                    color: theme.mutedText,
                    fontSize: 11,
                    formatter: (p: { value: number | null }) =>
                        p.value === null ? '—' : fmt.value.percent(p.value),
                },
                data: rows.map((r) => ({
                    value: r.rate,
                    itemStyle: {
                        color: toneColor(theme, r.tone),
                        borderRadius: [6, 6, 0, 0],
                    },
                })),
            },
        ],
    };
}

const isLocked = (key: AnalyticsProps['locked'][number]) =>
    props.locked.includes(key);
const noAging = computed(() => props.aging.every((r) => r.amount.minor === 0));
</script>

<template>
    <Head :title="t('pf.analytics.title')" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <PageHeader
            :icon="ChartColumn"
            :title="t('pf.analytics.title')"
            :description="t('pf.analytics.description')"
        >
            <template #actions>
                <div class="flex flex-wrap items-center gap-2">
                    <div
                        v-if="currencies.length > 1"
                        class="flex items-center gap-2"
                    >
                        <Label for="an-currency" class="sr-only">{{
                            t('pf.client.currency')
                        }}</Label>
                        <Select v-model="currencyModel">
                            <SelectTrigger id="an-currency" class="w-24"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="code in currencies"
                                    :key="code"
                                    :value="code"
                                    >{{ code }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                    <SegmentedControl
                        v-model="monthsModel"
                        :options="monthOptions"
                        :label="t('pf.analytics.period')"
                    />
                </div>
            </template>
        </PageHeader>

        <div
            class="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-5"
            data-test="analytics-kpis"
        >
            <KpiCard
                :label="t('pf.kpi.outstanding')"
                :value="pf.money(k.outstanding.amount)"
                :icon="Wallet"
                :hint="t('pf.kpi.invoices', { count: k.outstanding.count })"
            />
            <KpiCard
                :label="t('pf.kpi.overdue')"
                :value="pf.money(k.overdue.amount)"
                :icon="CalendarClock"
                tone="warning"
                :hint="
                    t('pf.kpi.overdue_share', {
                        share: fmt.percent(k.overdue.share),
                    })
                "
            />
            <KpiCard
                :label="t('pf.kpi.collected')"
                :value="pf.money(k.collected_this_month.amount)"
                :icon="HandCoins"
                :delta="
                    k.collected_this_month.delta_pct === null
                        ? null
                        : k.collected_this_month.delta_pct / 100
                "
            />
            <KpiCard
                :label="t('pf.kpi.dso')"
                :value="pf.days(k.dso.days)"
                :icon="Hourglass"
                tone="info"
                :hint="
                    k.dso.delta_days === null
                        ? null
                        : t('pf.kpi.dso_delta', {
                              days:
                                  (k.dso.delta_days > 0 ? '+' : '') +
                                  pf.num(k.dso.delta_days),
                          })
                "
            />
            <KpiCard
                :label="t('pf.kpi.at_risk')"
                :value="k.at_risk ? pf.money(k.at_risk.amount) : '—'"
                :icon="Gauge"
                tone="ai"
                :hint="
                    k.at_risk
                        ? t('pf.kpi.invoices', { count: k.at_risk.count })
                        : t('pf.kpi.risk_off')
                "
            />
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <ChartPanel
                :icon="ChartNoAxesColumn"
                :title="t('pf.analytics.aging')"
                :description="t('pf.analytics.aging_hint')"
                :empty="noAging"
                :empty-title="t('pf.analytics.no_outstanding')"
            >
                <template #chart>
                    <ThemedChart
                        :build="buildAging"
                        :label="t('pf.analytics.aging')"
                        height="16rem"
                        @select="openAging"
                    />
                </template>
                <template #table>
                    <table class="w-full text-sm">
                        <caption class="sr-only">
                            {{
                                t('pf.analytics.aging')
                            }}
                        </caption>
                        <thead class="text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="py-2 font-medium">
                                    {{ t('pf.invoices.aging') }}
                                </th>
                                <th class="py-2 text-right font-medium">
                                    {{ t('pf.analytics.count') }}
                                </th>
                                <th class="py-2 text-right font-medium">
                                    {{ t('pf.analytics.amount') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in aging"
                                :key="row.bucket"
                                class="border-t"
                            >
                                <td class="py-2">
                                    <Link
                                        :href="
                                            invoicesIndex({
                                                query: { aging: row.bucket },
                                            }).url
                                        "
                                        class="hover:underline"
                                        >{{ t(`pf.aging.${row.bucket}`) }}</Link
                                    >
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{ row.count }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{ pf.money(row.amount) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </ChartPanel>

            <ChartPanel
                :icon="TrendingUp"
                :title="t('pf.analytics.forecast')"
                :description="t('pf.analytics.forecast_hint')"
                :locked="isLocked('forecast')"
                :empty="!isLocked('forecast') && !forecastWeeks.length"
            >
                <template #chart>
                    <ThemedChart
                        :build="buildForecast"
                        :label="t('pf.analytics.forecast')"
                        height="16rem"
                    />
                </template>
                <template #table>
                    <table class="w-full text-sm">
                        <caption class="sr-only">
                            {{
                                t('pf.analytics.forecast')
                            }}
                        </caption>
                        <thead class="text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="py-2 font-medium">
                                    {{ t('pf.analytics.week') }}
                                </th>
                                <th class="py-2 text-right font-medium">
                                    {{ t('pf.analytics.expected') }}
                                </th>
                                <th class="py-2 text-right font-medium">
                                    {{ t('pf.analytics.at_risk') }}
                                </th>
                                <th class="py-2 text-right font-medium">
                                    {{ t('pf.analytics.count') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="week in forecastWeeks"
                                :key="week.week_start"
                                class="border-t"
                            >
                                <td class="py-2">
                                    {{ fmt.date(week.week_start) }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{ pf.money(week.expected) }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{ pf.money(week.at_risk) }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{ week.invoices }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </template>
                <template v-if="forecast" #footer>
                    <p class="text-xs text-muted-foreground">
                        {{
                            t('pf.analytics.later_total', {
                                amount: pf.money(forecast.later_total),
                            })
                        }}
                    </p>
                </template>
            </ChartPanel>

            <ChartPanel
                class="lg:col-span-2"
                :icon="Coins"
                :title="t('pf.analytics.monthly')"
                :description="t('pf.analytics.monthly_hint')"
                :empty="!monthly.length"
            >
                <template #chart>
                    <ThemedChart
                        :build="buildMonthly"
                        :label="t('pf.analytics.monthly')"
                        height="18rem"
                    />
                </template>
                <template #table>
                    <table class="w-full text-sm">
                        <caption class="sr-only">
                            {{
                                t('pf.analytics.monthly')
                            }}
                        </caption>
                        <thead class="text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="py-2 font-medium">
                                    {{ t('pf.analytics.month') }}
                                </th>
                                <th class="py-2 text-right font-medium">
                                    {{ t('pf.analytics.collected') }}
                                </th>
                                <th class="py-2 text-right font-medium">
                                    {{ t('pf.analytics.invoiced') }}
                                </th>
                                <th class="py-2 text-right font-medium">
                                    {{ t('pf.analytics.on_time_rate') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="point in monthly"
                                :key="point.month"
                                class="border-t"
                            >
                                <td class="py-2">
                                    {{ monthLabel(point.month) }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{ pf.money(point.collected) }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{ pf.money(point.invoiced) }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{
                                        point.on_time_rate === null
                                            ? '—'
                                            : fmt.percent(point.on_time_rate)
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </ChartPanel>

            <ChartPanel
                :icon="ChartLine"
                :title="t('pf.analytics.dso_trend')"
                :description="t('pf.analytics.dso_hint')"
                :empty="!dso_trend.some((p) => p.days !== null)"
            >
                <template #chart>
                    <ThemedChart
                        :build="buildDso"
                        :label="t('pf.analytics.dso_trend')"
                        height="14rem"
                    />
                </template>
                <template #table>
                    <table class="w-full text-sm">
                        <caption class="sr-only">
                            {{
                                t('pf.analytics.dso_trend')
                            }}
                        </caption>
                        <thead class="text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="py-2 font-medium">
                                    {{ t('pf.analytics.date') }}
                                </th>
                                <th class="py-2 text-right font-medium">
                                    {{ t('pf.analytics.dso') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="point in dso_trend"
                                :key="point.date"
                                class="border-t"
                            >
                                <td class="py-2">{{ fmt.date(point.date) }}</td>
                                <td class="py-2 text-right tabular-nums">
                                    {{
                                        point.days === null
                                            ? '—'
                                            : t('pf.client.days', {
                                                  days: point.days,
                                              })
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </ChartPanel>

            <ChartPanel
                :icon="UsersRound"
                :title="t('pf.analytics.late_payers')"
                :description="t('pf.analytics.late_payers_hint')"
                :empty="!late_payers.length"
                :empty-title="t('pf.analytics.no_late_payers')"
            >
                <template #chart>
                    <ThemedChart
                        :build="buildLatePayers"
                        :label="t('pf.analytics.late_payers')"
                        :height="`${Math.max(10, late_payers.length * 2.2 + 3)}rem`"
                        @select="openLatePayer"
                    />
                </template>
                <template #table>
                    <table class="w-full text-sm">
                        <caption class="sr-only">
                            {{
                                t('pf.analytics.late_payers')
                            }}
                        </caption>
                        <thead class="text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="py-2 font-medium">
                                    {{ t('pf.invoice.client') }}
                                </th>
                                <th class="py-2 text-right font-medium">
                                    {{ t('pf.client.avg_days_late') }}
                                </th>
                                <th class="py-2 text-right font-medium">
                                    {{ t('pf.client.overdue_balance') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in late_payers"
                                :key="row.client.id"
                                class="border-t"
                            >
                                <td class="py-2">
                                    <Link
                                        :href="showClient(row.client.id).url"
                                        class="hover:underline"
                                        >{{ row.client.name }}</Link
                                    >
                                    <ClientClassBadge
                                        class="ml-1"
                                        :value="row.client.late_payer_class"
                                    />
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{ pf.days(row.avg_days_late) }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{ pf.money(row.overdue_balance) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </ChartPanel>

            <ChartPanel
                :icon="Gauge"
                :title="t('pf.analytics.risk_mix')"
                :description="t('pf.analytics.risk_mix_hint')"
                :locked="isLocked('risk_mix')"
                :empty="!isLocked('risk_mix') && !riskTotal"
            >
                <template #chart>
                    <ThemedChart
                        :build="buildRiskMix"
                        :label="t('pf.analytics.risk_mix')"
                        height="6rem"
                    />
                    <ul class="mt-3 grid grid-cols-3 gap-2 text-sm">
                        <li v-for="row in risk_mix ?? []" :key="row.band">
                            <Link
                                :href="
                                    invoicesIndex({
                                        query: { risk: row.band, currency },
                                    }).url
                                "
                                class="block rounded-xl border p-2 hover:bg-muted/40"
                            >
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ t(`pf.risk.${row.band}`) }}</span
                                >
                                <span class="font-medium tabular-nums">{{
                                    pf.money(row.amount)
                                }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{
                                        t('pf.kpi.invoices', {
                                            count: row.count,
                                        })
                                    }}</span
                                >
                            </Link>
                        </li>
                    </ul>
                </template>
                <template #table>
                    <table class="w-full text-sm">
                        <caption class="sr-only">
                            {{
                                t('pf.analytics.risk_mix')
                            }}
                        </caption>
                        <tbody>
                            <tr
                                v-for="row in risk_mix ?? []"
                                :key="row.band"
                                class="border-t first:border-0"
                            >
                                <td class="py-2">
                                    {{ t(`pf.risk.${row.band}`) }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{ row.count }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{ pf.money(row.amount) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </ChartPanel>

            <ChartPanel
                :icon="BellRing"
                :title="t('pf.analytics.tone_effectiveness')"
                :description="t('pf.analytics.tone_hint')"
                :locked="isLocked('tone_effectiveness')"
                :empty="
                    !isLocked('tone_effectiveness') &&
                    !(tone_effectiveness ?? []).some((r) => r.sent > 0)
                "
            >
                <template #chart>
                    <ThemedChart
                        :build="buildTone"
                        :label="t('pf.analytics.tone_effectiveness')"
                        height="14rem"
                    />
                </template>
                <template #table>
                    <table class="w-full text-sm">
                        <caption class="sr-only">
                            {{
                                t('pf.analytics.tone_effectiveness')
                            }}
                        </caption>
                        <thead class="text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="py-2 font-medium">
                                    {{ t('pf.reminder.tone') }}
                                </th>
                                <th class="py-2 text-right font-medium">
                                    {{ t('pf.analytics.sent') }}
                                </th>
                                <th class="py-2 text-right font-medium">
                                    {{ t('pf.analytics.paid_7d') }}
                                </th>
                                <th class="py-2 text-right font-medium">
                                    {{ t('pf.analytics.rate') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in tone_effectiveness ?? []"
                                :key="row.tone"
                                class="border-t"
                            >
                                <td class="py-2">
                                    <ToneChip :tone="row.tone" />
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{ row.sent }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{ row.paid_within_7d }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{
                                        row.rate === null
                                            ? '—'
                                            : fmt.percent(row.rate)
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </ChartPanel>
        </div>
    </div>
</template>
