<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    BellRing,
    CirclePause,
    CirclePlay,
    Download,
    FileText,
    FilterX,
    Plus,
    SearchX,
    Send,
    SlidersHorizontal,
    Upload,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { DataColumn } from '@/components/common/DataTable.vue';
import DataTable from '@/components/common/DataTable.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import LaravelPagination from '@/components/common/LaravelPagination.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SearchableSelect from '@/components/common/SearchableSelect.vue';
import SearchInput from '@/components/common/SearchInput.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import ClientClassBadge from '@/components/pf/ClientClassBadge.vue';
import InvoiceStatusBadge from '@/components/pf/InvoiceStatusBadge.vue';
import RiskBadge from '@/components/pf/RiskBadge.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
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
import { withQuery } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { index as importsIndex } from '@/routes/imports';
import { bulk, create, exportMethod, index, show } from '@/routes/invoices';
import type {
    AgingBucket,
    InvoiceListItem,
    InvoicesIndexProps,
    RiskBand,
} from '@/types/payflow';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'pf.nav.invoices', href: index() }],
    },
});

const props = defineProps<InvoicesIndexProps>();
const pf = usePf();
const fmt = useFormat();

type Filters = {
    status: string | null;
    client_id: string | null;
    q: string | null;
    currency: string | null;
    risk: string | null;
    aging: string | null;
    due_from: string | null;
    due_to: string | null;
    sort: string | null;
    per_page: number | null;
};

const { filters, reset, active } = useFilters(
    () => index().url,
    {
        status: props.filters.status === 'all' ? null : props.filters.status,
        client_id: props.filters.client_id
            ? String(props.filters.client_id)
            : null,
        q: props.filters.q,
        currency: props.filters.currency,
        risk: props.filters.risk,
        aging: props.filters.aging,
        due_from: props.filters.due_from,
        due_to: props.filters.due_to,
        sort: props.filters.sort === 'due_date' ? null : props.filters.sort,
        per_page: props.filters.per_page === 25 ? null : props.filters.per_page,
    } as Filters,
    ['invoices', 'filters', 'counts', 'totals'],
);

const narrowed = () =>
    Boolean(
        filters.client_id ||
        filters.q ||
        filters.currency ||
        filters.risk ||
        filters.aging ||
        filters.due_from ||
        filters.due_to,
    );

const showMore = ref(
    Boolean(
        filters.risk || filters.aging || filters.due_from || filters.due_to,
    ),
);

const tabs = computed(() =>
    (
        [
            'all',
            'outstanding',
            'overdue',
            'due_soon',
            'draft',
            'partially_paid',
            'paid',
            'disputed',
            'uncollectible',
            'void',
        ] as const
    ).map((value) => ({ value, count: props.counts[value] ?? 0 })),
);

function setStatus(value: string): void {
    filters.status = value === 'all' ? null : value;
}

const statusValue = computed(() => filters.status ?? 'all');

const clientOptions = computed(() =>
    props.clients.map((c) => ({ value: String(c.value), label: c.label })),
);

const selectModel = (
    key: 'currency' | 'risk' | 'aging' | 'sort',
    fallback: string,
) =>
    computed({
        get: () => (filters[key] ?? fallback) as string,
        set: (value: string) => {
            filters[key] = value === fallback ? null : value;
        },
    });

const currencyModel = selectModel('currency', 'all');
const riskModel = selectModel('risk', 'all');
const agingModel = selectModel('aging', 'all');
const sortModel = selectModel('sort', 'due_date');

const perPageModel = computed({
    get: () => String(filters.per_page ?? 25),
    set: (value: string) => {
        filters.per_page = value === '25' ? null : Number(value);
    },
});

const risks: RiskBand[] = ['low', 'medium', 'high'];
const buckets: AgingBucket[] = [
    'current',
    'd1_30',
    'd31_60',
    'd61_90',
    'd90_plus',
];
const sorts = [
    'due_date',
    '-due_date',
    '-days_overdue',
    '-balance',
    'balance',
    '-risk',
    '-issue_date',
    'issue_date',
];

const selected = ref<number[]>([]);
watch(
    () => props.invoices.data,
    () => {
        selected.value = [];
    },
);

const allSelected = computed(
    () =>
        props.invoices.data.length > 0 &&
        selected.value.length === props.invoices.data.length,
);

function toggleAll(value: boolean | 'indeterminate'): void {
    selected.value =
        value === true ? props.invoices.data.map((row) => row.id) : [];
}

function toggleRow(id: number, value: boolean | 'indeterminate'): void {
    selected.value =
        value === true
            ? [...new Set([...selected.value, id])]
            : selected.value.filter((item) => item !== id);
}

const bulkBusy = ref<string | null>(null);

function runBulk(action: 'remind' | 'pause' | 'resume' | 'mark_sent'): void {
    bulkBusy.value = action;
    router.post(
        bulk().url,
        { action, ids: selected.value },
        {
            preserveScroll: true,
            onFinish: () => {
                bulkBusy.value = null;
            },
        },
    );
}

const exportUrl = computed(() => withQuery(exportMethod().url, { ...filters }));

const canBulk = computed(
    () => pf.can('reminders.send') || pf.can('invoices.manage'),
);

const columns = computed<DataColumn<InvoiceListItem>[]>(() => [
    ...(canBulk.value
        ? [
              {
                  id: 'select',
                  header: t('pf.common.select'),
                  srOnlyHeader: true,
                  class: 'w-10',
              },
          ]
        : []),
    { id: 'number', header: t('pf.invoice.number') },
    { id: 'client', header: t('pf.invoice.client'), hideBelow: 'sm' as const },
    { id: 'status', header: t('pf.invoice.status') },
    {
        id: 'due_date',
        header: t('pf.invoice.due_date'),
        hideBelow: 'md' as const,
    },
    {
        id: 'balance',
        header: t('pf.invoice.balance_due'),
        align: 'right' as const,
    },
    { id: 'risk', header: t('pf.invoice.risk'), hideBelow: 'lg' as const },
    {
        id: 'reminders',
        header: t('pf.invoice.reminders'),
        hideBelow: 'lg' as const,
    },
]);

function open(row: InvoiceListItem): void {
    router.visit(show(row.id).url);
}
</script>

<template>
    <Head :title="t('pf.invoices.title')" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <PageHeader
            :icon="FileText"
            :title="t('pf.invoices.title')"
            :description="t('pf.invoices.description')"
        >
            <template #actions>
                <Button variant="outline" as-child>
                    <a :href="exportUrl" data-test="invoices-export">
                        <Download class="size-4" aria-hidden="true" />
                        {{ t('pf.actions.export_csv') }}
                    </a>
                </Button>
                <Button v-if="pf.can('imports.run')" variant="outline" as-child>
                    <Link :href="importsIndex().url">
                        <Upload class="size-4" aria-hidden="true" />
                        {{ t('pf.actions.import_csv') }}
                    </Link>
                </Button>
                <Button v-if="pf.can('invoices.manage')" as-child>
                    <Link :href="create().url" data-test="invoice-create">
                        <Plus class="size-4" aria-hidden="true" />
                        {{ t('pf.invoices.create') }}
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <div
            v-if="totals.length"
            class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
            data-test="invoice-totals"
        >
            <div
                v-for="row in totals"
                :key="row.currency"
                class="flex items-center justify-between gap-4 rounded-2xl border bg-card px-4 py-3 shadow-soft"
            >
                <div>
                    <p class="text-xs text-muted-foreground">
                        {{
                            t('pf.invoices.outstanding_in', {
                                currency: row.currency,
                            })
                        }}
                    </p>
                    <p class="text-lg font-semibold tabular-nums">
                        {{ pf.money(row.outstanding) }}
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-xs text-muted-foreground">
                        {{ t('pf.status.overdue') }}
                    </p>
                    <p
                        :class="[
                            'font-medium tabular-nums',
                            row.overdue.minor > 0
                                ? 'text-destructive'
                                : 'text-muted-foreground',
                        ]"
                    >
                        {{ pf.money(row.overdue) }}
                    </p>
                </div>
            </div>
        </div>

        <nav
            class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0"
            :aria-label="t('pf.invoice.status')"
        >
            <ul class="flex min-w-max gap-1 border-b">
                <li v-for="tab in tabs" :key="tab.value">
                    <button
                        type="button"
                        :class="[
                            '-mb-px inline-flex items-center gap-2 border-b-2 px-3 py-2 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            statusValue === tab.value
                                ? 'border-primary font-medium text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        ]"
                        :aria-current="
                            statusValue === tab.value ? 'page' : undefined
                        "
                        :data-test="`status-tab-${tab.value}`"
                        @click="setStatus(tab.value)"
                    >
                        {{ t(`pf.status_filter.${tab.value}`) }}
                        <span
                            :class="[
                                'rounded-full px-1.5 text-xs tabular-nums',
                                tab.value === 'overdue' && tab.count
                                    ? 'bg-destructive-soft text-destructive-soft-foreground'
                                    : 'bg-muted text-muted-foreground',
                            ]"
                            >{{ tab.count }}</span
                        >
                    </button>
                </li>
            </ul>
        </nav>

        <section
            class="flex flex-col gap-3 rounded-2xl border bg-card p-4 shadow-soft"
            :aria-label="t('ui.common.filters')"
        >
            <div
                class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-[1.3fr_1.2fr_0.7fr_1fr_auto] lg:items-end"
            >
                <div class="grid gap-1.5">
                    <span class="text-xs text-muted-foreground">{{
                        t('ui.common.search')
                    }}</span>
                    <SearchInput
                        v-model="filters.q"
                        :placeholder="t('pf.invoices.search')"
                        :label="t('pf.invoices.search')"
                        class="sm:max-w-none"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label
                        for="inv-client"
                        class="text-xs text-muted-foreground"
                        >{{ t('pf.invoice.client') }}</Label
                    >
                    <SearchableSelect
                        id="inv-client"
                        v-model="filters.client_id"
                        :options="clientOptions"
                        clearable
                        :placeholder="t('pf.invoices.any_client')"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label
                        for="inv-currency"
                        class="text-xs text-muted-foreground"
                        >{{ t('pf.client.currency') }}</Label
                    >
                    <Select v-model="currencyModel">
                        <SelectTrigger id="inv-currency" class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">{{
                                t('ui.common.all')
                            }}</SelectItem>
                            <SelectItem
                                v-for="code in currencies"
                                :key="code"
                                :value="code"
                                >{{ code }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-1.5">
                    <Label
                        for="inv-sort"
                        class="text-xs text-muted-foreground"
                        >{{ t('pf.common.sort') }}</Label
                    >
                    <Select v-model="sortModel">
                        <SelectTrigger id="inv-sort" class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="sort in sorts"
                                :key="sort"
                                :value="sort"
                                >{{
                                    t(
                                        `pf.invoices.sort.${sort.replace('-', 'desc_')}`,
                                    )
                                }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
                <div class="flex gap-1">
                    <Button
                        variant="ghost"
                        :aria-expanded="showMore"
                        aria-controls="inv-more"
                        @click="showMore = !showMore"
                    >
                        <SlidersHorizontal class="size-4" aria-hidden="true" />
                        <span class="sr-only lg:not-sr-only">{{
                            t('pf.common.more_filters')
                        }}</span>
                    </Button>
                    <Button
                        variant="ghost"
                        :disabled="!active()"
                        @click="reset"
                    >
                        <FilterX class="size-4" aria-hidden="true" />
                        <span class="sr-only lg:not-sr-only">{{
                            t('ui.common.clear_filters')
                        }}</span>
                    </Button>
                </div>
            </div>
            <div
                v-show="showMore"
                id="inv-more"
                class="grid grid-cols-1 gap-3 border-t pt-3 sm:grid-cols-2 lg:grid-cols-4"
            >
                <div class="grid gap-1.5">
                    <Label
                        for="inv-risk"
                        class="text-xs text-muted-foreground"
                        >{{ t('pf.invoice.risk') }}</Label
                    >
                    <Select v-model="riskModel">
                        <SelectTrigger id="inv-risk" class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">{{
                                t('ui.common.all')
                            }}</SelectItem>
                            <SelectItem
                                v-for="band in risks"
                                :key="band"
                                :value="band"
                                >{{ t(`pf.risk.${band}`) }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-1.5">
                    <Label
                        for="inv-aging"
                        class="text-xs text-muted-foreground"
                        >{{ t('pf.invoices.aging') }}</Label
                    >
                    <Select v-model="agingModel">
                        <SelectTrigger id="inv-aging" class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">{{
                                t('ui.common.all')
                            }}</SelectItem>
                            <SelectItem
                                v-for="bucket in buckets"
                                :key="bucket"
                                :value="bucket"
                                >{{ t(`pf.aging.${bucket}`) }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-1.5">
                    <Label
                        for="inv-from"
                        class="text-xs text-muted-foreground"
                        >{{ t('pf.invoices.due_from') }}</Label
                    >
                    <Input
                        id="inv-from"
                        :model-value="filters.due_from ?? ''"
                        type="date"
                        :max="filters.due_to ?? undefined"
                        @update:model-value="
                            (v) => (filters.due_from = String(v) || null)
                        "
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="inv-to" class="text-xs text-muted-foreground">{{
                        t('pf.invoices.due_to')
                    }}</Label>
                    <Input
                        id="inv-to"
                        :model-value="filters.due_to ?? ''"
                        type="date"
                        :min="filters.due_from ?? undefined"
                        @update:model-value="
                            (v) => (filters.due_to = String(v) || null)
                        "
                    />
                </div>
            </div>
        </section>

        <div
            v-if="canBulk && invoices.data.length"
            class="flex flex-wrap items-center gap-3 rounded-2xl border bg-card px-4 py-2.5 text-sm shadow-soft"
            data-test="bulk-bar"
        >
            <Label class="flex items-center gap-2 font-normal">
                <Checkbox
                    :model-value="allSelected"
                    @update:model-value="toggleAll"
                />
                {{
                    selected.length
                        ? t('pf.invoices.selected', { count: selected.length })
                        : t('pf.invoices.select_page')
                }}
            </Label>
            <div v-if="selected.length" class="flex flex-wrap gap-2">
                <Button
                    v-if="pf.can('reminders.send')"
                    size="sm"
                    variant="outline"
                    :disabled="Boolean(bulkBusy)"
                    @click="runBulk('remind')"
                >
                    <Spinner v-if="bulkBusy === 'remind'" class="size-4" />
                    <BellRing v-else class="size-4" aria-hidden="true" />
                    {{ t('pf.actions.send_reminder') }}
                </Button>
                <Button
                    v-if="pf.can('reminders.send')"
                    size="sm"
                    variant="outline"
                    :disabled="Boolean(bulkBusy)"
                    @click="runBulk('pause')"
                >
                    <Spinner v-if="bulkBusy === 'pause'" class="size-4" />
                    <CirclePause v-else class="size-4" aria-hidden="true" />
                    {{ t('pf.actions.pause_reminders') }}
                </Button>
                <Button
                    v-if="pf.can('reminders.send')"
                    size="sm"
                    variant="outline"
                    :disabled="Boolean(bulkBusy)"
                    @click="runBulk('resume')"
                >
                    <Spinner v-if="bulkBusy === 'resume'" class="size-4" />
                    <CirclePlay v-else class="size-4" aria-hidden="true" />
                    {{ t('pf.actions.resume_reminders') }}
                </Button>
                <Button
                    v-if="pf.can('invoices.manage')"
                    size="sm"
                    variant="outline"
                    :disabled="Boolean(bulkBusy)"
                    @click="runBulk('mark_sent')"
                >
                    <Spinner v-if="bulkBusy === 'mark_sent'" class="size-4" />
                    <Send v-else class="size-4" aria-hidden="true" />
                    {{ t('pf.actions.mark_sent') }}
                </Button>
            </div>
        </div>

        <DataTable
            :columns="columns"
            :rows="invoices.data"
            :row-key="(row) => row.id"
            :row-attrs="
                (row) => ({
                    'data-test': 'invoice-row',
                    'data-number': row.number ?? '',
                    'data-status': row.display_status,
                })
            "
            :caption="t('pf.invoices.title')"
            clickable
            @row-click="open"
        >
            <template #cell-select="{ row }">
                <span @click.stop>
                    <Checkbox
                        :model-value="selected.includes(row.id)"
                        :aria-label="
                            t('pf.invoices.select_row', {
                                number: row.number ?? '',
                            })
                        "
                        @update:model-value="(v) => toggleRow(row.id, v)"
                    />
                </span>
            </template>
            <template #cell-number="{ row }">
                <Link
                    :href="show(row.id).url"
                    class="font-mono text-sm font-medium hover:underline focus-visible:underline"
                    @click.stop
                >
                    {{ row.number ?? t('pf.invoice.draft_number') }}
                </Link>
                <p class="text-xs text-muted-foreground sm:hidden">
                    {{ row.client.name }}
                </p>
                <p
                    v-if="row.source === 'import'"
                    class="text-xs text-muted-foreground"
                >
                    {{ t('pf.invoice.imported') }}
                </p>
            </template>
            <template #cell-client="{ row }">
                <span class="flex flex-col items-start gap-1">
                    <span class="truncate">{{ row.client.name }}</span>
                    <ClientClassBadge
                        v-if="row.client.late_payer_class !== 'reliable'"
                        :value="row.client.late_payer_class"
                    />
                </span>
            </template>
            <template #cell-status="{ row }">
                <InvoiceStatusBadge
                    :status="row.display_status"
                    :partial="row.is_partially_paid"
                    :days-overdue="row.days_overdue"
                />
                <StatusBadge
                    v-if="row.client_reported_paid_at"
                    class="mt-1"
                    tone="success"
                    :dot="false"
                    :label="t('pf.invoice.client_reports_paid')"
                />
            </template>
            <template #cell-due_date="{ row }">
                <span class="whitespace-nowrap">{{
                    fmt.date(row.due_date)
                }}</span>
                <p
                    v-if="
                        row.days_until_due !== null &&
                        row.days_until_due >= 0 &&
                        row.display_status !== 'paid'
                    "
                    class="text-xs text-muted-foreground"
                >
                    {{
                        row.days_until_due === 0
                            ? t('pf.invoice.due_today')
                            : t('pf.invoice.due_in', {
                                  days: row.days_until_due,
                              })
                    }}
                </p>
            </template>
            <template #cell-balance="{ row }">
                <span class="font-medium tabular-nums">{{
                    pf.money(row.balance_due)
                }}</span>
                <p
                    v-if="row.amount_paid.minor > 0"
                    class="text-xs text-muted-foreground tabular-nums"
                >
                    {{
                        t('pf.invoice.of_total', { total: pf.money(row.total) })
                    }}
                </p>
            </template>
            <template #cell-risk="{ row }">
                <RiskBadge
                    v-if="row.risk"
                    :band="row.risk.band"
                    :score="row.risk.score"
                />
                <span v-else class="text-muted-foreground">—</span>
            </template>
            <template #cell-reminders="{ row }">
                <span
                    v-if="row.reminders_paused"
                    class="inline-flex items-center gap-1 text-xs text-warning-soft-foreground"
                >
                    <CirclePause class="size-3.5" aria-hidden="true" />
                    {{
                        row.reminders_pause_source === 'import_review'
                            ? t('pf.invoice.paused_import')
                            : t('pf.client.paused')
                    }}
                </span>
                <span
                    v-else-if="row.next_reminder_at"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        t('pf.invoice.next_reminder_at', {
                            date: fmt.dateTime(row.next_reminder_at, 'short'),
                        })
                    }}
                </span>
                <span v-else class="text-xs text-muted-foreground">{{
                    t('pf.invoice.sent_count', {
                        count: row.reminders_sent_count,
                    })
                }}</span>
            </template>
            <template #empty>
                <EmptyState
                    :icon="active() ? SearchX : FileText"
                    :tone="active() ? 'info' : 'primary'"
                    :title="
                        narrowed() || filters.status
                            ? t('pf.invoices.empty_filtered')
                            : t('pf.invoices.empty_title')
                    "
                    :description="
                        narrowed() || filters.status
                            ? t('ui.common.try_other_filters')
                            : t('pf.invoices.empty_body')
                    "
                >
                    <Button v-if="active()" variant="outline" @click="reset">{{
                        t('ui.common.clear_filters')
                    }}</Button>
                    <div
                        v-else-if="pf.can('invoices.manage')"
                        class="flex flex-wrap justify-center gap-2"
                    >
                        <Button as-child>
                            <Link :href="create().url">
                                <Plus class="size-4" aria-hidden="true" />
                                {{ t('pf.invoices.create') }}
                            </Link>
                        </Button>
                        <Button
                            v-if="pf.can('imports.run')"
                            variant="outline"
                            as-child
                        >
                            <Link :href="importsIndex().url">
                                <Upload class="size-4" aria-hidden="true" />
                                {{ t('pf.actions.import_csv') }}
                            </Link>
                        </Button>
                    </div>
                </EmptyState>
            </template>
            <template #footer>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <LaravelPagination
                        :links="invoices.links"
                        :meta="invoices.meta"
                        :count="invoices.data.length"
                        :only="['invoices', 'filters', 'counts', 'totals']"
                    />
                    <div
                        class="flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        <Label for="inv-per-page" class="font-normal">{{
                            t('pf.common.per_page')
                        }}</Label>
                        <Select v-model="perPageModel">
                            <SelectTrigger id="inv-per-page" class="h-8 w-20"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="25">25</SelectItem>
                                <SelectItem value="50">50</SelectItem>
                                <SelectItem value="100">100</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>
            </template>
        </DataTable>
    </div>
</template>
