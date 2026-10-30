<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    BellRing,
    CalendarClock,
    ChartNoAxesColumn,
    CircleCheck,
    FileText,
    Gauge,
    HandCoins,
    Hourglass,
    Lightbulb,
    PartyPopper,
    Plus,
    ShieldCheck,
    TrendingUp,
    UsersRound,
    Wallet,
    X,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import ThemedChart from '@/components/charts/ThemedChart.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import KpiCard from '@/components/common/KpiCard.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import ChartPanel from '@/components/pf/ChartPanel.vue';
import ClientClassBadge from '@/components/pf/ClientClassBadge.vue';
import PayoutRow from '@/components/pf/guard/PayoutRow.vue';
import InvoiceStatusBadge from '@/components/pf/InvoiceStatusBadge.vue';
import RiskBadge from '@/components/pf/RiskBadge.vue';
import ToneChip from '@/components/pf/ToneChip.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { usePf } from '@/composables/pf/usePf';
import { usePfCharts } from '@/composables/pf/usePfCharts';
import { useFormat } from '@/composables/useFormat';
import { endpoints } from '@/lib/endpoints';
import { humanize, t, tOr } from '@/lib/i18n';
import { SEVERITY_TONE } from '@/lib/pf/status';
import { show as showClient, create as createClient } from '@/routes/clients';
import { index as guardIndex } from '@/routes/guard';
import {
    create as createInvoice,
    index as invoicesIndex,
    show as showInvoice,
} from '@/routes/invoices';
import { dismiss } from '@/routes/pf/onboarding';
import { create as createVendor } from '@/routes/vendors';
import { index as knowledgeIndex } from '@/routes/knowledge';
import { integrations, payments } from '@/routes/settings';
import type { DashboardProps } from '@/types/payflow';

const props = defineProps<DashboardProps>();
const pf = usePf();
const fmt = useFormat();
const page = usePage();
const charts = usePfCharts(() => props.currency);

const firstName = computed(
    () => page.props.auth.user?.name.split(' ')[0] ?? '',
);
const greetingKey = computed(() => {
    const hour = new Date().getHours();

    if (hour < 5) {
        return 'ui.dashboard.greeting.night';
    }

    if (hour < 12) {
        return 'ui.dashboard.greeting.morning';
    }

    return hour < 18
        ? 'ui.dashboard.greeting.afternoon'
        : 'ui.dashboard.greeting.evening';
});

const currencyModel = computed({
    get: () => props.currency,
    set: (value: string) =>
        router.get(
            endpoints.dashboard().url,
            { currency: value },
            { preserveScroll: true, preserveState: true, replace: true },
        ),
});

const k = computed(() => props.kpis);

const steps = computed(() => {
    const o = props.onboarding;

    return [
        { key: 'clients', done: o.has_clients, href: createClient().url },
        { key: 'invoices', done: o.has_invoices, href: createInvoice().url },
        { key: 'payments', done: o.provider_connected, href: payments().url },
        { key: 'telegram', done: o.telegram_linked, href: integrations().url },
        { key: 'vendors', done: o.has_vendors, href: createVendor().url },
        {
            key: 'knowledge',
            done: o.documents_indexed > 0,
            href: knowledgeIndex().url,
        },
    ];
});
const doneCount = computed(() => steps.value.filter((s) => s.done).length);
const showOnboarding = ref(!props.onboarding.dismissed && doneCount.value < 6);
const ring = computed(() => 2 * Math.PI * 18);

function dismissOnboarding(): void {
    showOnboarding.value = false;
    router.post(
        dismiss().url,
        {},
        { preserveScroll: true, preserveState: true },
    );
}

const insightIcons: Record<string, Component> = {
    bank_change_pending: ShieldCheck,
    chronic_client_overdue: UsersRound,
    forecast_gap: TrendingUp,
    unviewed_invoices: FileText,
    reminders_failed: BellRing,
};

const noAging = computed(() => props.aging.every((r) => r.amount.minor === 0));
const forecastLocked = computed(() => !pf.entitled('analytics_advanced'));
const buildAging = computed(() => charts.aging(props.aging));
const buildForecast = computed(() =>
    charts.forecast(props.forecast?.weeks ?? []),
);

function openAging(index: number): void {
    const row = props.aging[index];

    if (row) {
        router.visit(
            invoicesIndex({
                query: { aging: row.bucket, currency: props.currency },
            }).url,
        );
    }
}

const maxLate = computed(() =>
    Math.max(1, ...props.late_payers.map((r) => r.avg_days_late)),
);
</script>

<template>
    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <PageHeader
            :title="t(greetingKey, { name: firstName })"
            :description="t('pf.dashboard.description')"
        >
            <template #actions>
                <div class="flex flex-wrap items-center gap-2">
                    <template v-if="currencies.length > 1">
                        <Label for="dash-currency" class="sr-only">{{
                            t('pf.client.currency')
                        }}</Label>
                        <Select v-model="currencyModel">
                            <SelectTrigger id="dash-currency" class="w-24"
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
                    </template>
                    <Button v-if="pf.can('invoices.manage')" as-child>
                        <Link :href="createInvoice().url"
                            ><Plus class="size-4" aria-hidden="true" />{{
                                t('pf.invoices.create')
                            }}</Link
                        >
                    </Button>
                </div>
            </template>
        </PageHeader>

        <section
            v-if="showOnboarding"
            class="flex flex-col gap-4 rounded-2xl border bg-card p-5 shadow-soft sm:flex-row sm:items-center"
            data-test="pf-onboarding"
        >
            <div class="flex items-center gap-4">
                <svg
                    viewBox="0 0 44 44"
                    class="size-14 shrink-0 -rotate-90"
                    role="img"
                    :aria-label="
                        t('pf.dashboard.onboarding_progress', {
                            done: doneCount,
                            total: 6,
                        })
                    "
                >
                    <circle
                        cx="22"
                        cy="22"
                        r="18"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="4"
                        class="text-muted"
                    />
                    <circle
                        cx="22"
                        cy="22"
                        r="18"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="4"
                        stroke-linecap="round"
                        class="text-primary transition-all motion-reduce:transition-none"
                        :stroke-dasharray="ring"
                        :stroke-dashoffset="ring * (1 - doneCount / 6)"
                    />
                </svg>
                <div>
                    <p class="font-semibold">
                        {{ t('pf.dashboard.onboarding_title') }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{
                            t('pf.dashboard.onboarding_progress', {
                                done: doneCount,
                                total: 6,
                            })
                        }}
                    </p>
                </div>
            </div>
            <ul
                class="grid flex-1 grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3"
            >
                <li v-for="step in steps" :key="step.key">
                    <Link
                        :href="step.href"
                        :class="[
                            'flex items-center gap-2 rounded-xl border px-3 py-2 text-sm transition-colors hover:bg-muted/40',
                            step.done
                                ? 'text-muted-foreground line-through'
                                : '',
                        ]"
                    >
                        <CircleCheck
                            :class="[
                                'size-4 shrink-0',
                                step.done
                                    ? 'text-success'
                                    : 'text-muted-foreground/50',
                            ]"
                            aria-hidden="true"
                        />
                        {{ t(`pf.dashboard.steps.${step.key}`) }}
                    </Link>
                </li>
            </ul>
            <Button
                variant="ghost"
                size="icon"
                class="self-start"
                :aria-label="t('pf.dashboard.dismiss')"
                @click="dismissOnboarding"
            >
                <X class="size-4" aria-hidden="true" />
            </Button>
        </section>

        <div
            class="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-5"
            data-test="dashboard-stats"
        >
            <Link
                :href="
                    invoicesIndex({
                        query: { status: 'outstanding', currency },
                    }).url
                "
                class="rounded-2xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <KpiCard
                    :label="t('pf.kpi.outstanding')"
                    :value="pf.money(k.outstanding.amount)"
                    :icon="Wallet"
                    :hint="t('pf.kpi.invoices', { count: k.outstanding.count })"
                />
            </Link>
            <Link
                :href="
                    invoicesIndex({ query: { status: 'overdue', currency } })
                        .url
                "
                class="rounded-2xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
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
            </Link>
            <Link
                :href="invoicesIndex({ query: { status: 'paid' } }).url"
                class="rounded-2xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
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
            </Link>
            <Link
                :href="endpoints.analytics.index().url"
                class="rounded-2xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <KpiCard
                    :label="t('pf.kpi.dso')"
                    :value="pf.days(k.dso.days)"
                    :icon="Hourglass"
                    tone="info"
                    :hint="
                        k.dso.delta_days === null
                            ? t('pf.dashboard.dso_hint')
                            : t('pf.kpi.dso_delta', {
                                  days:
                                      (k.dso.delta_days > 0 ? '+' : '') +
                                      pf.num(k.dso.delta_days),
                              })
                    "
                />
            </Link>
            <Link
                :href="invoicesIndex({ query: { risk: 'high', currency } }).url"
                class="col-span-2 rounded-2xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:col-span-1"
            >
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
            </Link>
        </div>

        <div
            v-if="insights.length"
            class="grid grid-cols-1 gap-3 md:grid-cols-3"
            data-test="insights"
        >
            <div
                v-for="insight in insights"
                :key="insight.code"
                class="flex flex-col gap-2 rounded-2xl border border-ai/30 bg-ai-soft p-4 text-sm text-ai-soft-foreground"
            >
                <p class="flex items-center gap-2 font-medium">
                    <component
                        :is="insightIcons[insight.code] ?? Lightbulb"
                        class="size-4"
                        aria-hidden="true"
                    />
                    {{
                        tOr(
                            `pf.insights.titles.${insight.code}`,
                            humanize(insight.code),
                        )
                    }}
                </p>
                <p class="opacity-90">
                    {{
                        t(
                            `pf.insights.bodies.${insight.code}`,
                            pf.params(insight.params),
                        )
                    }}
                </p>
                <Link
                    v-if="insight.action"
                    :href="insight.action.href"
                    class="mt-auto text-sm font-medium underline-offset-2 hover:underline"
                    >{{ t(insight.action.label_key) }}</Link
                >
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <ChartPanel
                :icon="ChartNoAxesColumn"
                :title="t('pf.analytics.aging')"
                :empty="noAging"
                :empty-title="t('pf.analytics.no_outstanding')"
            >
                <template #chart>
                    <ThemedChart
                        :build="buildAging"
                        :label="t('pf.analytics.aging')"
                        height="15rem"
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
                        <tbody>
                            <tr
                                v-for="row in aging"
                                :key="row.bucket"
                                class="border-t first:border-0"
                            >
                                <td class="py-2">
                                    {{ t(`pf.aging.${row.bucket}`) }}
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
                :locked="forecastLocked"
                :empty="!forecastLocked && !forecast?.weeks.length"
            >
                <template #chart>
                    <ThemedChart
                        :build="buildForecast"
                        :label="t('pf.analytics.forecast')"
                        height="15rem"
                    />
                </template>
                <template #table>
                    <table class="w-full text-sm">
                        <caption class="sr-only">
                            {{
                                t('pf.analytics.forecast')
                            }}
                        </caption>
                        <tbody>
                            <tr
                                v-for="week in forecast?.weeks ?? []"
                                :key="week.week_start"
                                class="border-t first:border-0"
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
                            </tr>
                        </tbody>
                    </table>
                </template>
            </ChartPanel>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <SectionCard
                :icon="FileText"
                :title="t('pf.dashboard.attention')"
                class="lg:col-span-2"
                flush
            >
                <EmptyState
                    v-if="!attention.length"
                    compact
                    :icon="PartyPopper"
                    :title="t('pf.dashboard.caught_up')"
                    :description="t('pf.dashboard.caught_up_body')"
                />
                <ul v-else class="divide-y" data-test="attention">
                    <li
                        v-for="invoice in attention"
                        :key="invoice.id"
                        class="flex flex-wrap items-center justify-between gap-3 px-5 py-3"
                        :data-number="invoice.number"
                    >
                        <div class="min-w-0">
                            <Link
                                :href="showInvoice(invoice.id).url"
                                class="font-mono text-sm font-medium hover:underline"
                                >{{ invoice.number }}</Link
                            >
                            <p
                                class="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <span class="truncate">{{
                                    invoice.client.name
                                }}</span>
                                <ClientClassBadge
                                    v-if="
                                        invoice.client.late_payer_class !==
                                        'reliable'
                                    "
                                    :value="invoice.client.late_payer_class"
                                />
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <InvoiceStatusBadge
                                :status="invoice.display_status"
                                :partial="invoice.is_partially_paid"
                                :days-overdue="invoice.days_overdue"
                            />
                            <RiskBadge
                                v-if="invoice.risk"
                                :band="invoice.risk.band"
                            />
                            <span
                                class="min-w-24 text-right font-medium tabular-nums"
                                >{{ pf.money(invoice.balance_due) }}</span
                            >
                            <Button
                                v-if="pf.can('reminders.send')"
                                size="sm"
                                variant="outline"
                                as-child
                            >
                                <Link :href="showInvoice(invoice.id).url">{{
                                    t('pf.dashboard.remind')
                                }}</Link>
                            </Button>
                        </div>
                    </li>
                </ul>
            </SectionCard>

            <SectionCard :icon="BellRing" :title="t('pf.dashboard.upcoming')">
                <p
                    v-if="!upcoming_reminders.length"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('pf.dashboard.nothing_scheduled') }}
                </p>
                <ol v-else class="relative space-y-3 border-l pl-5">
                    <li
                        v-for="reminder in upcoming_reminders"
                        :key="reminder.id"
                        class="relative"
                    >
                        <span
                            class="absolute top-1.5 -left-[1.6rem] size-2.5 rounded-full bg-primary ring-4 ring-background"
                            aria-hidden="true"
                        />
                        <p class="text-xs text-muted-foreground">
                            {{ fmt.dateTime(reminder.scheduled_for, 'short') }}
                        </p>
                        <p class="flex flex-wrap items-center gap-2 text-sm">
                            <ToneChip :tone="reminder.tone" />
                            <Link
                                :href="showInvoice(reminder.invoice.id).url"
                                class="font-mono text-xs hover:underline"
                                >{{ reminder.invoice.number }}</Link
                            >
                            <span class="truncate text-muted-foreground">{{
                                reminder.client.name
                            }}</span>
                        </p>
                    </li>
                </ol>
            </SectionCard>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <SectionCard
                :icon="UsersRound"
                :title="t('pf.analytics.late_payers')"
            >
                <p
                    v-if="!late_payers.length"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('pf.analytics.no_late_payers') }}
                </p>
                <ul v-else class="space-y-3">
                    <li v-for="row in late_payers" :key="row.client.id">
                        <div
                            class="flex items-center justify-between gap-2 text-sm"
                        >
                            <Link
                                :href="showClient(row.client.id).url"
                                class="truncate hover:underline"
                                >{{ row.client.name }}</Link
                            >
                            <span
                                class="text-xs text-muted-foreground tabular-nums"
                                >{{ pf.days(row.avg_days_late) }} ·
                                {{ pf.money(row.overdue_balance) }}</span
                            >
                        </div>
                        <div
                            class="mt-1 h-2 overflow-hidden rounded-full bg-muted"
                            aria-hidden="true"
                        >
                            <div
                                :class="[
                                    'h-full rounded-full',
                                    row.client.late_payer_class === 'chronic'
                                        ? 'bg-destructive'
                                        : 'bg-warning',
                                ]"
                                :style="{
                                    width: `${(row.avg_days_late / maxLate) * 100}%`,
                                }"
                            />
                        </div>
                    </li>
                </ul>
            </SectionCard>

            <SectionCard
                v-if="guard"
                :icon="ShieldCheck"
                :title="t('pf.guard.title')"
            >
                <template #actions>
                    <Button size="sm" variant="ghost" as-child
                        ><Link :href="guardIndex().url">{{
                            t('pf.dashboard.open_guard')
                        }}</Link></Button
                    >
                </template>
                <div
                    v-if="
                        !guard.pending_approvals.length &&
                        !guard.open_alerts.length
                    "
                    class="flex flex-col items-center gap-2 py-4 text-center"
                >
                    <ShieldCheck
                        class="size-8 text-success"
                        aria-hidden="true"
                    />
                    <p class="text-sm text-muted-foreground">
                        {{ t('pf.guard.nothing_waiting') }}
                    </p>
                </div>
                <template v-else>
                    <p class="mb-3 text-sm text-muted-foreground">
                        {{
                            t('pf.dashboard.guard_summary', {
                                awaiting: guard.awaiting_me,
                                changes: guard.bank_changes_pending,
                                high: guard.bank_changes_high,
                            })
                        }}
                    </p>
                    <ul v-if="guard.open_alerts.length" class="mb-3 space-y-2">
                        <li
                            v-for="alert in guard.open_alerts"
                            :key="alert.id"
                            class="flex items-center justify-between gap-2 text-sm"
                        >
                            <span class="truncate"
                                >{{ t(`pf.alerts.${alert.type}`) }} ·
                                <span class="text-muted-foreground">{{
                                    alert.subject.label
                                }}</span></span
                            >
                            <StatusBadge
                                :tone="SEVERITY_TONE[alert.severity]"
                                :label="t(`pf.severity.${alert.severity}`)"
                            />
                        </li>
                    </ul>
                    <ul
                        v-if="guard.pending_approvals.length"
                        class="-mx-5 divide-y border-t"
                    >
                        <PayoutRow
                            v-for="payout in guard.pending_approvals"
                            :key="payout.id"
                            :payout="payout"
                        />
                    </ul>
                </template>
            </SectionCard>
        </div>
    </div>
</template>
