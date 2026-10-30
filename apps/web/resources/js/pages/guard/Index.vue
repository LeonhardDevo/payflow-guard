<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    BellRing,
    CheckCheck,
    CircleAlert,
    CirclePause,
    HandCoins,
    Landmark,
    Plus,
    Settings2,
    ShieldCheck,
    UserCheck,
    Wallet,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/common/EmptyState.vue';
import KpiCard from '@/components/common/KpiCard.vue';
import LaravelPagination from '@/components/common/LaravelPagination.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import GuardBand from '@/components/pf/guard/GuardBand.vue';
import GuardErrorDialog from '@/components/pf/guard/GuardErrorDialog.vue';
import GuardRulesCard from '@/components/pf/guard/GuardRulesCard.vue';
import PayoutRow from '@/components/pf/guard/PayoutRow.vue';
import ReasonDialog from '@/components/pf/invoice/ReasonDialog.vue';
import RiskBadge from '@/components/pf/RiskBadge.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useFilters } from '@/composables/useFilters';
import { useFormat } from '@/composables/useFormat';
import { usePf } from '@/composables/pf/usePf';
import { t } from '@/lib/i18n';
import { SEVERITY_TONE } from '@/lib/pf/status';
import { index } from '@/routes/guard';
import { acknowledge, resolve } from '@/routes/guard/alerts';
import { show as reviewChange } from '@/routes/guard/bank-changes';
import {
    index as payoutsIndex,
    create as createPayout,
} from '@/routes/payouts';
import { edit as securityEdit } from '@/routes/security';
import { guard as guardSettings } from '@/routes/settings';
import type {
    AlertSeverity,
    GuardAlertItem,
    GuardIndexProps,
    Money,
} from '@/types/payflow-guard';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'pf.nav.guard', href: index() }],
    },
});

const props = defineProps<GuardIndexProps>();
const pf = usePf();
const fmt = useFormat();

const { filters } = useFilters(
    () => index().url,
    {
        severity: props.alert_filters.severity,
        status:
            props.alert_filters.status === 'active'
                ? null
                : props.alert_filters.status,
    } as { severity: string | null; status: string | null },
    ['alerts', 'alert_filters'],
);

const severityModel = computed({
    get: () => filters.severity ?? 'all',
    set: (value: string) => (filters.severity = value === 'all' ? null : value),
});
const statusModel = computed({
    get: () => filters.status ?? 'active',
    set: (value: string) =>
        (filters.status = value === 'active' ? null : value),
});

const moneyList = (items: Money[]) =>
    items.length ? items.map((m) => pf.money(m)).join(' + ') : '—';

const busy = ref<number | null>(null);

function ack(alert: GuardAlertItem): void {
    busy.value = alert.id;
    router.post(
        acknowledge(alert.id).url,
        {},
        { preserveScroll: true, onFinish: () => (busy.value = null) },
    );
}

const resolveTarget = ref<GuardAlertItem | null>(null);
const resolveOpen = computed({
    get: () => resolveTarget.value !== null,
    set: (value: boolean) => {
        if (!value) {
            resolveTarget.value = null;
        }
    },
});

const severities: AlertSeverity[] = ['critical', 'warning', 'info'];
const canAlerts = computed(() => pf.can('guard.alerts'));
</script>

<template>
    <Head :title="t('guard.overview.title')" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <GuardBand />
        <PageHeader
            :icon="ShieldCheck"
            :title="t('guard.overview.title')"
            :description="t('guard.overview.description')"
        >
            <template #actions>
                <Button
                    v-if="pf.can('guard.configure')"
                    variant="outline"
                    as-child
                >
                    <Link :href="guardSettings().url"
                        ><Settings2 class="size-4" aria-hidden="true" />{{
                            t('guard.overview.settings')
                        }}</Link
                    >
                </Button>
                <Button v-if="pf.can('payouts.create')" as-child>
                    <Link :href="createPayout().url"
                        ><Plus class="size-4" aria-hidden="true" />{{
                            t('guard.payouts.create')
                        }}</Link
                    >
                </Button>
            </template>
        </PageHeader>

        <div
            v-if="me.missing_second_factor"
            class="flex flex-col gap-3 rounded-2xl border border-warning/40 bg-warning-soft p-4 text-sm text-warning-soft-foreground sm:flex-row sm:items-center sm:justify-between"
        >
            <p class="flex items-start gap-3">
                <CircleAlert
                    class="mt-0.5 size-5 shrink-0"
                    aria-hidden="true"
                />{{ t('guard.overview.missing_second_factor') }}
            </p>
            <Button size="sm" variant="outline" as-child
                ><Link :href="securityEdit().url">{{
                    t('guard.stepup.setup_second_factor')
                }}</Link></Button
            >
        </div>
        <div
            v-else-if="
                me.privileged &&
                me.eligible_from &&
                new Date(me.eligible_from).getTime() > Date.now()
            "
            class="flex items-start gap-3 rounded-2xl border border-info/30 bg-info-soft p-4 text-sm text-info-soft-foreground"
        >
            <CircleAlert class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <p>
                {{
                    t('guard.overview.eligible_from', {
                        date: fmt.dateTime(me.eligible_from, 'medium'),
                    })
                }}
            </p>
        </div>

        <div
            class="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-5"
            data-test="guard-kpis"
        >
            <KpiCard
                :label="t('guard.overview.kpi.awaiting_me')"
                :value="kpis.awaiting_me"
                :icon="UserCheck"
                tone="ai"
            />
            <KpiCard
                :label="t('guard.overview.kpi.pending')"
                :value="kpis.pending_approvals.count"
                :icon="HandCoins"
                :hint="moneyList(kpis.pending_approvals.amounts)"
            />
            <KpiCard
                :label="t('guard.overview.kpi.bank_changes')"
                :value="kpis.bank_changes_pending"
                :icon="Landmark"
                tone="warning"
                :hint="
                    kpis.bank_changes_high
                        ? t('guard.overview.kpi.high_count', {
                              count: kpis.bank_changes_high,
                          })
                        : null
                "
            />
            <KpiCard
                :label="t('guard.overview.kpi.on_hold')"
                :value="kpis.on_hold"
                :icon="CirclePause"
                tone="warning"
            />
            <KpiCard
                :label="t('guard.overview.kpi.released')"
                :value="moneyList(kpis.released_this_month)"
                :icon="Wallet"
                tone="info"
            />
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="flex min-w-0 flex-col gap-6 lg:col-span-2">
                <SectionCard
                    :icon="HandCoins"
                    :title="t('guard.overview.needs_decision')"
                    flush
                >
                    <template #actions>
                        <Button size="sm" variant="ghost" as-child
                            ><Link :href="payoutsIndex().url">{{
                                t('guard.overview.all_payouts')
                            }}</Link></Button
                        >
                    </template>
                    <EmptyState
                        v-if="!needs_decision.length"
                        compact
                        :icon="ShieldCheck"
                        :title="t('guard.overview.nothing_waiting')"
                        :description="t('guard.overview.nothing_waiting_body')"
                    />
                    <ul v-else class="divide-y">
                        <PayoutRow
                            v-for="payout in needs_decision"
                            :key="payout.id"
                            :payout="payout"
                        />
                    </ul>
                </SectionCard>

                <SectionCard
                    :icon="Landmark"
                    :title="t('guard.overview.bank_changes')"
                    flush
                >
                    <EmptyState
                        v-if="!bank_changes.length"
                        compact
                        :icon="Landmark"
                        tone="muted"
                        :title="t('guard.overview.no_bank_changes')"
                    />
                    <ul v-else class="divide-y">
                        <li v-for="item in bank_changes" :key="item.account.id">
                            <Link
                                :href="reviewChange(item.account.id).url"
                                class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 transition-colors hover:bg-muted/40 focus-visible:bg-muted/40 focus-visible:outline-none"
                                data-test="guard-bank-change"
                            >
                                <span class="min-w-0">
                                    <span class="block font-medium">{{
                                        item.vendor.name
                                    }}</span>
                                    <span
                                        class="block font-mono text-xs text-muted-foreground"
                                        >{{ item.account.display }} ·
                                        {{ item.account.bank_country }}</span
                                    >
                                </span>
                                <span class="flex items-center gap-2">
                                    <span
                                        class="text-xs text-muted-foreground"
                                        >{{
                                            fmt.relative(
                                                item.account.requested_at,
                                            )
                                        }}</span
                                    >
                                    <RiskBadge
                                        :band="item.account.risk_band"
                                        :score="item.account.risk_score"
                                    />
                                </span>
                            </Link>
                        </li>
                    </ul>
                </SectionCard>

                <SectionCard
                    :icon="BellRing"
                    :title="t('guard.overview.alerts')"
                    flush
                >
                    <template #actions>
                        <div class="flex flex-wrap gap-2">
                            <Label for="alert-severity" class="sr-only">{{
                                t('guard.overview.severity')
                            }}</Label>
                            <Select v-model="severityModel">
                                <SelectTrigger
                                    id="alert-severity"
                                    class="h-8 w-36"
                                    ><SelectValue
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">{{
                                        t('guard.overview.any_severity')
                                    }}</SelectItem>
                                    <SelectItem
                                        v-for="severity in severities"
                                        :key="severity"
                                        :value="severity"
                                        >{{
                                            t(`guard.severity.${severity}`)
                                        }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                            <Label for="alert-status" class="sr-only">{{
                                t('guard.fields.status')
                            }}</Label>
                            <Select v-model="statusModel">
                                <SelectTrigger
                                    id="alert-status"
                                    class="h-8 w-36"
                                    ><SelectValue
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="status in [
                                            'active',
                                            'open',
                                            'acknowledged',
                                            'resolved',
                                        ]"
                                        :key="status"
                                        :value="status"
                                        >{{
                                            t(`guard.alert_status.${status}`)
                                        }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>
                    </template>
                    <EmptyState
                        v-if="!alerts.data.length"
                        compact
                        :icon="ShieldCheck"
                        :title="t('guard.overview.no_alerts')"
                    />
                    <ul v-else class="divide-y" data-test="guard-alerts">
                        <li
                            v-for="alert in alerts.data"
                            :key="alert.id"
                            class="flex flex-wrap items-start justify-between gap-3 px-5 py-3"
                            :data-type="alert.type"
                            :data-severity="alert.severity"
                        >
                            <div class="min-w-0">
                                <p
                                    class="flex flex-wrap items-center gap-2 text-sm"
                                >
                                    <StatusBadge
                                        :tone="SEVERITY_TONE[alert.severity]"
                                        :label="
                                            t(
                                                `guard.severity.${alert.severity}`,
                                            )
                                        "
                                    />
                                    <span class="font-medium">{{
                                        t(`guard.alerts.${alert.type}`)
                                    }}</span>
                                </p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    <Link
                                        v-if="alert.subject.url"
                                        :href="alert.subject.url"
                                        class="hover:underline"
                                        >{{ alert.subject.label }}</Link
                                    >
                                    <span v-else>{{
                                        alert.subject.label
                                    }}</span>
                                    ·
                                    {{
                                        fmt.dateTime(alert.created_at, 'short')
                                    }}
                                    <template v-if="alert.acknowledged_by">
                                        ·
                                        {{
                                            t(
                                                'guard.overview.acknowledged_by',
                                                {
                                                    name: alert.acknowledged_by
                                                        .name,
                                                },
                                            )
                                        }}</template
                                    >
                                </p>
                            </div>
                            <div
                                v-if="canAlerts && alert.status !== 'resolved'"
                                class="flex gap-1"
                            >
                                <Button
                                    v-if="alert.status === 'open'"
                                    size="sm"
                                    variant="ghost"
                                    :disabled="busy === alert.id"
                                    @click="ack(alert)"
                                >
                                    <Spinner
                                        v-if="busy === alert.id"
                                        class="size-4"
                                    />
                                    <CheckCheck
                                        v-else
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    {{ t('guard.overview.acknowledge') }}
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    @click="resolveTarget = alert"
                                    >{{ t('guard.overview.resolve') }}</Button
                                >
                            </div>
                            <StatusBadge
                                v-else-if="alert.status === 'resolved'"
                                tone="neutral"
                                :label="t('guard.alert_status.resolved')"
                            />
                        </li>
                    </ul>
                    <div v-if="alerts.data.length" class="border-t px-4 py-3">
                        <LaravelPagination
                            :links="alerts.links"
                            :meta="alerts.meta"
                            :count="alerts.data.length"
                            :only="['alerts', 'alert_filters']"
                        />
                    </div>
                </SectionCard>
            </div>

            <GuardRulesCard
                :rules="rules"
                class="lg:sticky lg:top-24 lg:self-start"
            />
        </div>
    </div>

    <ReasonDialog
        v-if="resolveTarget"
        v-model:open="resolveOpen"
        :url="resolve(resolveTarget.id).url"
        field="note"
        :min="0"
        :max="500"
        :title="t('guard.overview.resolve_title')"
        :description="t(`guard.alerts.${resolveTarget.type}`)"
        :label="t('guard.overview.resolve_note')"
        :confirm-label="t('guard.overview.resolve')"
    />
    <GuardErrorDialog />
</template>
