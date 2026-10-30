<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    BellRing,
    Building2,
    CalendarClock,
    ChartColumn,
    CirclePause,
    CirclePlay,
    FileText,
    History,
    Mail,
    MapPin,
    Pencil,
    Phone,
    Plus,
    Trash2,
    UserRound,
    UserX,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ThemedChart from '@/components/charts/ThemedChart.vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import KpiCard from '@/components/common/KpiCard.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import SegmentedControl from '@/components/common/SegmentedControl.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import InputError from '@/components/InputError.vue';
import ClientClassBadge from '@/components/pf/ClientClassBadge.vue';
import InvoiceStatusBadge from '@/components/pf/InvoiceStatusBadge.vue';
import PfTimeline from '@/components/pf/PfTimeline.vue';
import ToneChip from '@/components/pf/ToneChip.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { usePf } from '@/composables/pf/usePf';
import { categoryAxis, axisTooltip, baseGrid, valueAxis } from '@/lib/charts';
import { t } from '@/lib/i18n';
import { REMINDER_STATUS_TONE } from '@/lib/pf/status';
import type { ChartTheme } from '@/lib/theme';
import { anonymise, destroy, edit, index, pause, show } from '@/routes/clients';
import {
    create as createInvoice,
    show as showInvoice,
} from '@/routes/invoices';
import type { ClientShowProps } from '@/types/payflow';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'pf.nav.clients', href: index() }],
    },
});

const props = defineProps<ClientShowProps>();
const pf = usePf();
const fmt = useFormat();

const c = computed(() => props.client);

const pauseOpen = ref(false);
const deleteOpen = ref(false);
const anonymiseOpen = ref(false);
const deleting = ref(false);

const pauseForm = useForm({
    paused: true,
    until: '' as string,
    reason: '' as string,
    acknowledge_opt_out: false,
});

function openPause(): void {
    pauseForm.reset();
    pauseForm.clearErrors();
    pauseForm.paused = !c.value.reminders_paused;
    pauseOpen.value = true;
}

const optedOut = computed(
    () => c.value.reminders_pause_source === 'recipient_opt_out',
);

function submitPause(): void {
    pauseForm
        .transform((data) => ({
            paused: data.paused,
            until: data.paused && data.until ? data.until : null,
            reason: data.paused && data.reason ? data.reason : null,
            acknowledge_opt_out: data.acknowledge_opt_out,
        }))
        .post(pause(c.value.id).url, {
            preserveScroll: true,
            onSuccess: () => (pauseOpen.value = false),
        });
}

function confirmDelete(): void {
    deleting.value = true;
    router.delete(destroy(c.value.id).url, {
        onFinish: () => {
            deleting.value = false;
            deleteOpen.value = false;
        },
    });
}

const anonymiseForm = useForm({ confirm_name: '', natural_person: true });

function submitAnonymise(): void {
    anonymiseForm.post(anonymise(c.value.id).url, {
        preserveScroll: true,
        onSuccess: () => (anonymiseOpen.value = false),
    });
}

const behaviourView = ref<'chart' | 'table'>('chart');
const viewOptions = computed(() => [
    { value: 'chart' as const, label: t('pf.common.chart') },
    { value: 'table' as const, label: t('pf.common.table') },
]);

function buildBehaviour(theme: ChartTheme) {
    const labels = props.behaviour.map((row) => row.number);

    return {
        grid: baseGrid(),
        tooltip: axisTooltip(theme),
        xAxis: categoryAxis(theme, labels),
        yAxis: valueAxis(theme, (value: number) =>
            t('pf.client.days_short', { days: pf.num(value) }),
        ),
        series: [
            {
                type: 'bar',
                name: t('pf.client.days_late'),
                barMaxWidth: 28,
                data: props.behaviour.map((row) => ({
                    value: row.days_late,
                    itemStyle: {
                        color:
                            row.days_late > 3
                                ? theme.destructive
                                : row.days_late > 0
                                  ? theme.warning
                                  : theme.primary,
                        borderRadius: [6, 6, 0, 0],
                    },
                })),
            },
            {
                type: 'line',
                name: t('pf.client.grace_line'),
                data: labels.map(() => 3),
                symbol: 'none',
                lineStyle: { type: 'dashed', width: 1, color: theme.axis },
                tooltip: { show: false },
            },
        ],
    };
}

const behaviourSummary = computed(() =>
    t('pf.client.behaviour_aria', {
        count: props.behaviour.length,
        avg: pf.num(c.value.avg_days_late),
    }),
);

function onBarClick(index: number): void {
    const row = props.behaviour[index];

    if (row) {
        router.visit(showInvoice(row.invoice_id).url);
    }
}
</script>

<template>
    <Head :title="c.name" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <PageHeader
            :icon="Building2"
            :title="c.name"
            :description="c.legal_name"
        >
            <template #meta>
                <div class="flex flex-wrap items-center gap-2">
                    <ClientClassBadge :value="c.late_payer_class" />
                    <StatusBadge
                        v-if="c.reminders_paused"
                        tone="warning"
                        :icon="CirclePause"
                        :dot="false"
                        :label="
                            optedOut
                                ? t('pf.client.opted_out')
                                : c.reminders_paused_until
                                  ? t('pf.client.paused_until', {
                                        date: fmt.date(
                                            c.reminders_paused_until,
                                        ),
                                    })
                                  : t('pf.client.paused')
                        "
                        data-test="client-paused"
                    />
                    <StatusBadge
                        v-if="c.anonymised_at"
                        tone="neutral"
                        :icon="UserX"
                        :dot="false"
                        :label="t('pf.client.anonymised')"
                    />
                    <span
                        v-for="tag in c.tags"
                        :key="tag"
                        class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                        >{{ tag }}</span
                    >
                </div>
            </template>
            <template #actions>
                <Button
                    v-if="can.pause"
                    variant="outline"
                    data-test="client-pause"
                    @click="openPause"
                >
                    <component
                        :is="c.reminders_paused ? CirclePlay : CirclePause"
                        class="size-4"
                        aria-hidden="true"
                    />
                    {{
                        c.reminders_paused
                            ? t('pf.actions.resume_reminders')
                            : t('pf.actions.pause_reminders')
                    }}
                </Button>
                <Button v-if="can.edit" variant="outline" as-child>
                    <Link :href="edit(c.id).url">
                        <Pencil class="size-4" aria-hidden="true" />
                        {{ t('pf.actions.edit') }}
                    </Link>
                </Button>
                <Button v-if="can.create_invoice" as-child>
                    <Link
                        :href="
                            createInvoice({ query: { client_id: c.id } }).url
                        "
                        data-test="client-new-invoice"
                    >
                        <Plus class="size-4" aria-hidden="true" />
                        {{ t('pf.invoices.create') }}
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <KpiCard
                :label="t('pf.client.open_balance')"
                :value="pf.money(c.open_balance)"
                :icon="FileText"
                :hint="
                    t('pf.client.open_count', { count: c.open_invoices_count })
                "
            />
            <KpiCard
                :label="t('pf.client.overdue_balance')"
                :value="pf.money(c.overdue_balance)"
                :icon="CalendarClock"
                tone="warning"
            />
            <KpiCard
                :label="t('pf.client.avg_days_late')"
                :value="c.paid_invoices_count ? pf.days(c.avg_days_late) : '—'"
                :icon="History"
                tone="info"
                :hint="
                    t('pf.client.paid_count', { count: c.paid_invoices_count })
                "
            />
            <KpiCard
                :label="t('pf.client.on_time_rate')"
                :value="
                    c.on_time_rate === null ? '—' : fmt.percent(c.on_time_rate)
                "
                :icon="ChartColumn"
                tone="ai"
                :hint="
                    c.stats_computed_at
                        ? t('pf.client.stats_at', {
                              date: fmt.relative(c.stats_computed_at),
                          })
                        : null
                "
            />
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="flex flex-col gap-6 lg:col-span-2">
                <SectionCard
                    :icon="ChartColumn"
                    :title="t('pf.client.behaviour_title')"
                    :description="t('pf.client.behaviour_hint')"
                >
                    <template #actions>
                        <SegmentedControl
                            v-if="behaviour.length"
                            v-model="behaviourView"
                            :options="viewOptions"
                            :label="t('pf.common.view')"
                        />
                    </template>
                    <EmptyState
                        v-if="!behaviour.length"
                        compact
                        :icon="ChartColumn"
                        tone="muted"
                        :title="t('pf.client.behaviour_empty')"
                    />
                    <ThemedChart
                        v-else-if="behaviourView === 'chart'"
                        :build="buildBehaviour"
                        :label="behaviourSummary"
                        height="220px"
                        @select="onBarClick"
                    />
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <caption class="sr-only">
                                {{
                                    t('pf.client.behaviour_title')
                                }}
                            </caption>
                            <thead
                                class="text-left text-xs text-muted-foreground"
                            >
                                <tr>
                                    <th class="py-2 font-medium">
                                        {{ t('pf.invoice.number') }}
                                    </th>
                                    <th class="py-2 font-medium">
                                        {{ t('pf.invoice.due_date') }}
                                    </th>
                                    <th class="py-2 font-medium">
                                        {{ t('pf.client.paid_on') }}
                                    </th>
                                    <th class="py-2 text-right font-medium">
                                        {{ t('pf.client.days_late') }}
                                    </th>
                                    <th class="py-2 text-right font-medium">
                                        {{ t('pf.invoice.total') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in behaviour"
                                    :key="row.invoice_id"
                                    class="border-t"
                                >
                                    <td class="py-2">
                                        <Link
                                            :href="
                                                showInvoice(row.invoice_id).url
                                            "
                                            class="font-mono text-xs hover:underline"
                                            >{{ row.number }}</Link
                                        >
                                    </td>
                                    <td class="py-2">
                                        {{ fmt.date(row.due_date) }}
                                    </td>
                                    <td class="py-2">
                                        {{ fmt.date(row.paid_on) }}
                                    </td>
                                    <td class="py-2 text-right tabular-nums">
                                        {{ row.days_late }}
                                    </td>
                                    <td class="py-2 text-right tabular-nums">
                                        {{ pf.money(row.amount) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </SectionCard>

                <SectionCard
                    :icon="FileText"
                    :title="t('pf.client.invoices')"
                    flush
                >
                    <EmptyState
                        v-if="!invoices.length"
                        compact
                        :icon="FileText"
                        :title="t('pf.client.no_invoices')"
                    >
                        <Button v-if="can.create_invoice" size="sm" as-child>
                            <Link
                                :href="
                                    createInvoice({
                                        query: { client_id: c.id },
                                    }).url
                                "
                                >{{ t('pf.invoices.create') }}</Link
                            >
                        </Button>
                    </EmptyState>
                    <ul v-else class="divide-y">
                        <li v-for="invoice in invoices" :key="invoice.id">
                            <Link
                                :href="showInvoice(invoice.id).url"
                                class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 transition-colors hover:bg-muted/40 focus-visible:bg-muted/40 focus-visible:outline-none"
                                data-test="client-invoice-row"
                            >
                                <span class="min-w-0">
                                    <span
                                        class="font-mono text-sm font-medium"
                                        >{{
                                            invoice.number ??
                                            t('pf.invoice.draft_number')
                                        }}</span
                                    >
                                    <span
                                        class="block text-xs text-muted-foreground"
                                    >
                                        {{
                                            t('pf.invoice.due_on', {
                                                date: fmt.date(
                                                    invoice.due_date,
                                                ),
                                            })
                                        }}
                                    </span>
                                </span>
                                <span class="flex items-center gap-3">
                                    <InvoiceStatusBadge
                                        :status="invoice.display_status"
                                        :partial="invoice.is_partially_paid"
                                        :days-overdue="invoice.days_overdue"
                                    />
                                    <span class="text-right">
                                        <span
                                            class="block font-medium tabular-nums"
                                            >{{
                                                pf.money(invoice.balance_due)
                                            }}</span
                                        >
                                        <span
                                            class="block text-xs text-muted-foreground tabular-nums"
                                            >{{
                                                t('pf.invoice.of_total', {
                                                    total: pf.money(
                                                        invoice.total,
                                                    ),
                                                })
                                            }}</span
                                        >
                                    </span>
                                </span>
                            </Link>
                        </li>
                    </ul>
                </SectionCard>

                <SectionCard
                    :icon="BellRing"
                    :title="t('pf.client.reminders')"
                    flush
                >
                    <EmptyState
                        v-if="!reminders.length"
                        compact
                        :icon="BellRing"
                        tone="muted"
                        :title="t('pf.client.no_reminders')"
                    />
                    <ul v-else class="divide-y">
                        <li
                            v-for="reminder in reminders"
                            :key="reminder.id"
                            class="flex flex-wrap items-center justify-between gap-3 px-5 py-3"
                        >
                            <span class="flex min-w-0 items-center gap-2">
                                <ToneChip :tone="reminder.tone" />
                                <Link
                                    :href="showInvoice(reminder.invoice.id).url"
                                    class="font-mono text-xs hover:underline"
                                    >{{ reminder.invoice.number ?? '—' }}</Link
                                >
                                <span
                                    class="truncate text-sm text-muted-foreground"
                                    >{{ reminder.subject }}</span
                                >
                            </span>
                            <span
                                class="flex items-center gap-3 text-xs text-muted-foreground"
                            >
                                <time
                                    :datetime="
                                        reminder.sent_at ??
                                        reminder.scheduled_for
                                    "
                                    >{{
                                        fmt.dateTime(
                                            reminder.sent_at ??
                                                reminder.scheduled_for,
                                            'short',
                                        )
                                    }}</time
                                >
                                <StatusBadge
                                    :tone="
                                        REMINDER_STATUS_TONE[reminder.status]
                                    "
                                    :label="
                                        t(
                                            `pf.reminder_status.${reminder.status}`,
                                        )
                                    "
                                />
                            </span>
                        </li>
                    </ul>
                </SectionCard>
            </div>

            <div class="flex flex-col gap-6">
                <SectionCard :icon="UserRound" :title="t('pf.client.contact')">
                    <dl class="space-y-3 text-sm">
                        <div
                            v-if="c.contact_name"
                            class="flex items-center gap-2"
                        >
                            <UserRound
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <dd>{{ c.contact_name }}</dd>
                        </div>
                        <div class="flex items-start gap-2">
                            <Mail
                                class="mt-0.5 size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <dd class="min-w-0 break-all">
                                {{ c.email ?? t('pf.client.no_email') }}
                                <span
                                    v-if="c.cc_emails.length"
                                    class="block text-xs text-muted-foreground"
                                    >cc: {{ c.cc_emails.join(', ') }}</span
                                >
                            </dd>
                        </div>
                        <div v-if="c.phone" class="flex items-center gap-2">
                            <Phone
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <dd>{{ c.phone }}</dd>
                        </div>
                        <div
                            v-if="c.address || c.country"
                            class="flex items-start gap-2"
                        >
                            <MapPin
                                class="mt-0.5 size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <dd class="whitespace-pre-line">
                                {{
                                    [c.address, c.country]
                                        .filter(Boolean)
                                        .join('\n')
                                }}
                            </dd>
                        </div>
                    </dl>
                    <dl
                        class="mt-5 grid grid-cols-2 gap-x-4 gap-y-3 border-t pt-4 text-sm"
                    >
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('pf.client.currency') }}
                            </dt>
                            <dd>
                                {{ c.currency }} · {{ c.locale.toUpperCase() }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('pf.client.payment_terms') }}
                            </dt>
                            <dd>
                                {{
                                    t('pf.client.days', {
                                        days: c.effective_payment_terms_days,
                                    })
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('pf.client.timezone') }}
                            </dt>
                            <dd class="truncate">{{ c.effective_timezone }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('pf.client.owner') }}
                            </dt>
                            <dd>{{ c.owner?.name ?? '—' }}</dd>
                        </div>
                        <div v-if="c.tax_id" class="col-span-2">
                            <dt class="text-xs text-muted-foreground">
                                {{ t('pf.client.tax_id') }}
                            </dt>
                            <dd class="font-mono text-xs">{{ c.tax_id }}</dd>
                        </div>
                    </dl>
                </SectionCard>

                <SectionCard
                    :icon="BellRing"
                    :title="t('pf.client.collections')"
                >
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">
                                {{ t('pf.client.reminder_policy') }}
                            </dt>
                            <dd class="text-right">
                                {{ c.reminder_policy.name }}
                                <span
                                    v-if="c.reminder_policy.inherited"
                                    class="block text-xs text-muted-foreground"
                                    >{{ t('pf.common.inherited') }}</span
                                >
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">
                                {{ t('pf.client.tone_ceiling') }}
                            </dt>
                            <dd>
                                {{ t(`pf.tone_ceiling.${c.tone_ceiling}`) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">
                                {{ t('pf.late_fee.title') }}
                            </dt>
                            <dd class="text-right">
                                {{ t(`pf.late_fee.types.${c.late_fee.type}`) }}
                                <span
                                    v-if="c.late_fee.inherited"
                                    class="block text-xs text-muted-foreground"
                                    >{{ t('pf.common.inherited') }}</span
                                >
                            </dd>
                        </div>
                        <div
                            v-if="c.contract_document"
                            class="flex justify-between gap-3"
                        >
                            <dt class="text-muted-foreground">
                                {{ t('pf.client.contract') }}
                            </dt>
                            <dd class="text-right">
                                {{ c.contract_document.title }}
                            </dd>
                        </div>
                    </dl>
                    <div
                        v-if="next_reminder"
                        class="mt-4 rounded-xl border bg-muted/30 p-3 text-sm"
                        data-test="client-next-reminder"
                    >
                        <p class="text-xs text-muted-foreground">
                            {{ t('pf.client.next_reminder') }}
                        </p>
                        <p class="mt-1 flex flex-wrap items-center gap-2">
                            <ToneChip :tone="next_reminder.tone" />
                            <span>{{
                                fmt.dateTime(
                                    next_reminder.scheduled_for,
                                    'medium',
                                )
                            }}</span>
                            <Link
                                :href="
                                    showInvoice(next_reminder.invoice.id).url
                                "
                                class="font-mono text-xs hover:underline"
                                >{{ next_reminder.invoice.number }}</Link
                            >
                        </p>
                    </div>
                    <p
                        v-if="c.pause_reason && c.reminders_paused"
                        class="mt-3 text-xs text-muted-foreground"
                    >
                        {{
                            t('pf.client.pause_reason', {
                                reason: c.pause_reason,
                            })
                        }}
                    </p>
                </SectionCard>

                <SectionCard v-if="c.notes" :title="t('pf.client.notes')">
                    <p
                        class="text-sm whitespace-pre-line text-muted-foreground"
                    >
                        {{ c.notes }}
                    </p>
                </SectionCard>

                <SectionCard :icon="History" :title="t('pf.client.activity')">
                    <EmptyState
                        v-if="!timeline.length"
                        compact
                        :icon="History"
                        tone="muted"
                        :title="t('pf.common.no_activity')"
                    />
                    <div v-else class="pl-4">
                        <PfTimeline :events="timeline" show-invoice />
                    </div>
                </SectionCard>

                <SectionCard
                    v-if="
                        can.delete ||
                        (pf.can('privacy.manage') && !c.anonymised_at)
                    "
                    tone="danger"
                    :title="t('pf.common.danger_zone')"
                >
                    <div class="flex flex-col gap-2">
                        <Button
                            v-if="pf.can('privacy.manage') && !c.anonymised_at"
                            variant="outline"
                            @click="anonymiseOpen = true"
                        >
                            <UserX class="size-4" aria-hidden="true" />
                            {{ t('pf.client.anonymise') }}
                        </Button>
                        <Button
                            v-if="can.delete"
                            variant="destructive"
                            data-test="client-delete"
                            @click="deleteOpen = true"
                        >
                            <Trash2 class="size-4" aria-hidden="true" />
                            {{ t('pf.client.delete') }}
                        </Button>
                    </div>
                </SectionCard>
            </div>
        </div>
    </div>

    <Dialog v-model:open="pauseOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    pauseForm.paused
                        ? t('pf.pause.title_pause')
                        : t('pf.pause.title_resume')
                }}</DialogTitle>
                <DialogDescription>{{
                    pauseForm.paused
                        ? t('pf.pause.client_pause_body')
                        : t('pf.pause.client_resume_body')
                }}</DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="submitPause">
                <template v-if="pauseForm.paused">
                    <div class="grid gap-2">
                        <Label for="pause-until">{{
                            t('pf.pause.until')
                        }}</Label>
                        <Input
                            id="pause-until"
                            v-model="pauseForm.until"
                            type="date"
                        />
                        <p class="text-xs text-muted-foreground">
                            {{ t('pf.pause.until_hint') }}
                        </p>
                        <InputError :message="pauseForm.errors.until" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="pause-reason">{{
                            t('pf.pause.reason')
                        }}</Label>
                        <Input
                            id="pause-reason"
                            v-model="pauseForm.reason"
                            maxlength="255"
                        />
                        <InputError :message="pauseForm.errors.reason" />
                    </div>
                </template>
                <Label
                    v-else-if="optedOut"
                    class="flex items-start gap-3 rounded-xl border border-warning/40 bg-warning-soft p-3 text-sm font-normal text-warning-soft-foreground"
                >
                    <Checkbox
                        v-model="pauseForm.acknowledge_opt_out"
                        class="mt-0.5"
                        data-test="ack-opt-out"
                    />
                    <span>{{ t('pf.pause.ack_opt_out') }}</span>
                </Label>
                <InputError :message="pauseForm.errors.acknowledge_opt_out" />
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="pauseOpen = false"
                        >{{ t('ui.common.cancel') }}</Button
                    >
                    <Button
                        type="submit"
                        :disabled="
                            pauseForm.processing ||
                            (!pauseForm.paused &&
                                optedOut &&
                                !pauseForm.acknowledge_opt_out)
                        "
                    >
                        <Spinner v-if="pauseForm.processing" class="size-4" />
                        {{
                            pauseForm.paused
                                ? t('pf.actions.pause_reminders')
                                : t('pf.actions.resume_reminders')
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        v-model:open="deleteOpen"
        destructive
        :title="t('pf.client.delete_title', { name: c.name })"
        :description="t('pf.client.delete_body')"
        :confirm-label="t('ui.common.delete')"
        :processing="deleting"
        @confirm="confirmDelete"
    />

    <Dialog v-model:open="anonymiseOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ t('pf.client.anonymise_title') }}</DialogTitle>
                <DialogDescription>{{
                    t('pf.client.anonymise_body')
                }}</DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="submitAnonymise">
                <div class="grid gap-2">
                    <Label for="anon-confirm">{{
                        t('pf.client.anonymise_confirm', { name: c.name })
                    }}</Label>
                    <Input
                        id="anon-confirm"
                        v-model="anonymiseForm.confirm_name"
                        autocomplete="off"
                    />
                    <InputError :message="anonymiseForm.errors.confirm_name" />
                </div>
                <Label class="flex items-start gap-3 text-sm font-normal">
                    <Checkbox
                        v-model="anonymiseForm.natural_person"
                        class="mt-0.5"
                    />
                    <span>{{ t('pf.client.natural_person') }}</span>
                </Label>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="anonymiseOpen = false"
                        >{{ t('ui.common.cancel') }}</Button
                    >
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="
                            anonymiseForm.processing ||
                            anonymiseForm.confirm_name !== c.name
                        "
                    >
                        <Spinner
                            v-if="anonymiseForm.processing"
                            class="size-4"
                        />
                        {{ t('pf.client.anonymise') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
