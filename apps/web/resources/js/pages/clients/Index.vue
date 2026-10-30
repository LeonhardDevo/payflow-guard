<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Building2,
    CirclePause,
    FilterX,
    Plus,
    SearchX,
    Upload,
} from '@lucide/vue';
import { computed } from 'vue';
import type { DataColumn } from '@/components/common/DataTable.vue';
import DataTable from '@/components/common/DataTable.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SearchInput from '@/components/common/SearchInput.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import ClientClassBadge from '@/components/pf/ClientClassBadge.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import LaravelPagination from '@/components/common/LaravelPagination.vue';
import { useFilters } from '@/composables/useFilters';
import { usePf } from '@/composables/pf/usePf';
import { t } from '@/lib/i18n';
import { LATE_PAYER_CLASSES } from '@/lib/pf/status';
import { create, index, show } from '@/routes/clients';
import { index as importsIndex } from '@/routes/imports';
import type {
    ClientListItem,
    ClientsIndexProps,
    LatePayerClass,
} from '@/types/payflow';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'pf.nav.clients', href: index() }],
    },
});

const props = defineProps<ClientsIndexProps>();
const pf = usePf();

const { filters, reset, active } = useFilters(
    () => index().url,
    {
        q: props.filters.q ?? null,
        class: props.filters.class ?? null,
        owner_id: props.filters.owner_id ?? null,
        paused:
            props.filters.paused === null || props.filters.paused === undefined
                ? null
                : props.filters.paused
                  ? '1'
                  : '0',
        sort: props.filters.sort === 'name' ? null : props.filters.sort,
    } as {
        q: string | null;
        class: string | null;
        owner_id: string | number | null;
        paused: string | null;
        sort: string | null;
    },
    ['clients', 'filters', 'class_counts'],
);

const total = computed(() =>
    Object.values(props.class_counts).reduce((sum, n) => sum + n, 0),
);

function setClass(value: LatePayerClass | null): void {
    filters.class = filters.class === value ? null : value;
}

const ownerModel = computed({
    get: () => (filters.owner_id ? String(filters.owner_id) : 'all'),
    set: (value: string) => {
        filters.owner_id = value === 'all' ? null : value;
    },
});

const pausedModel = computed({
    get: () => (filters.paused === null ? 'all' : String(filters.paused)),
    set: (value: string) => {
        filters.paused = value === 'all' ? null : value;
    },
});

const sortModel = computed({
    get: () => String(filters.sort ?? 'name'),
    set: (value: string) => {
        filters.sort = value === 'name' ? null : value;
    },
});

const sorts = ['name', '-open_balance', '-overdue_balance', '-avg_days_late'];

const columns = computed<DataColumn<ClientListItem>[]>(() => [
    { id: 'name', header: t('pf.client.name') },
    { id: 'class', header: t('pf.client.behaviour'), hideBelow: 'sm' },
    {
        id: 'avg_days_late',
        header: t('pf.client.avg_days_late'),
        align: 'right',
        hideBelow: 'md',
    },
    {
        id: 'open_balance',
        header: t('pf.client.open_balance'),
        align: 'right',
    },
    {
        id: 'overdue_balance',
        header: t('pf.client.overdue_balance'),
        align: 'right',
        hideBelow: 'sm',
    },
    { id: 'owner', header: t('pf.client.owner'), hideBelow: 'lg' },
]);

function open(row: ClientListItem): void {
    router.visit(show(row.id).url);
}
</script>

<template>
    <Head :title="t('pf.clients.title')" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <PageHeader
            :icon="Building2"
            :title="t('pf.clients.title')"
            :description="t('pf.clients.description')"
        >
            <template #actions>
                <Button v-if="pf.can('imports.run')" variant="outline" as-child>
                    <Link :href="importsIndex().url">
                        <Upload class="size-4" aria-hidden="true" />
                        {{ t('pf.actions.import_csv') }}
                    </Link>
                </Button>
                <Button v-if="pf.can('clients.manage')" as-child>
                    <Link :href="create().url" data-test="client-create">
                        <Plus class="size-4" aria-hidden="true" />
                        {{ t('pf.clients.create') }}
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <div
            class="flex flex-wrap gap-2"
            role="group"
            :aria-label="t('pf.client.behaviour')"
        >
            <button
                type="button"
                :class="[
                    'inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    !filters.class
                        ? 'border-primary bg-primary/10 text-foreground'
                        : 'bg-card text-muted-foreground hover:text-foreground',
                ]"
                :aria-pressed="!filters.class"
                @click="setClass(null)"
            >
                {{ t('ui.common.all') }}
                <span class="tabular-nums">{{ total }}</span>
            </button>
            <button
                v-for="value in LATE_PAYER_CLASSES"
                :key="value"
                type="button"
                :class="[
                    'inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    filters.class === value
                        ? 'border-primary bg-primary/10 text-foreground'
                        : 'bg-card text-muted-foreground hover:text-foreground',
                ]"
                :aria-pressed="filters.class === value"
                :data-test="`class-filter-${value}`"
                @click="setClass(value)"
            >
                {{ t(`pf.class_filter.${value}`) }}
                <span class="tabular-nums">{{ class_counts[value] ?? 0 }}</span>
            </button>
        </div>

        <section
            class="grid grid-cols-1 gap-3 rounded-2xl border bg-card p-4 shadow-soft sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1fr_auto] lg:items-end"
            :aria-label="t('ui.common.filters')"
        >
            <div class="grid gap-1.5">
                <span class="text-xs text-muted-foreground">{{
                    t('ui.common.search')
                }}</span>
                <SearchInput
                    v-model="filters.q"
                    :placeholder="t('pf.clients.search')"
                    :label="t('pf.clients.search')"
                    class="sm:max-w-none"
                />
            </div>
            <div class="grid gap-1.5">
                <Label
                    for="clients-owner"
                    class="text-xs text-muted-foreground"
                    >{{ t('pf.client.owner') }}</Label
                >
                <Select v-model="ownerModel">
                    <SelectTrigger id="clients-owner" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">{{
                            t('pf.clients.any_owner')
                        }}</SelectItem>
                        <SelectItem value="me">{{
                            t('pf.clients.my_clients')
                        }}</SelectItem>
                        <SelectItem
                            v-for="owner in owners"
                            :key="owner.value"
                            :value="String(owner.value)"
                            >{{ owner.label }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
            <div class="grid gap-1.5">
                <Label
                    for="clients-paused"
                    class="text-xs text-muted-foreground"
                    >{{ t('pf.clients.reminders') }}</Label
                >
                <Select v-model="pausedModel">
                    <SelectTrigger id="clients-paused" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">{{
                            t('ui.common.all')
                        }}</SelectItem>
                        <SelectItem value="0">{{
                            t('pf.clients.reminders_active')
                        }}</SelectItem>
                        <SelectItem value="1">{{
                            t('pf.clients.reminders_paused')
                        }}</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div class="grid gap-1.5">
                <Label
                    for="clients-sort"
                    class="text-xs text-muted-foreground"
                    >{{ t('pf.common.sort') }}</Label
                >
                <Select v-model="sortModel">
                    <SelectTrigger id="clients-sort" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="sort in sorts"
                            :key="sort"
                            :value="sort"
                            >{{
                                t(
                                    `pf.clients.sort.${sort.replace('-', 'desc_')}`,
                                )
                            }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
            <Button variant="ghost" :disabled="!active()" @click="reset">
                <FilterX class="size-4" aria-hidden="true" />
                {{ t('ui.common.clear_filters') }}
            </Button>
        </section>

        <DataTable
            :columns="columns"
            :rows="clients.data"
            :row-key="(row) => row.id"
            :row-attrs="
                (row) => ({
                    'data-test': 'client-row',
                    'data-id': String(row.id),
                })
            "
            :caption="t('pf.clients.title')"
            clickable
            @row-click="open"
        >
            <template #cell-name="{ row }">
                <div class="min-w-0">
                    <Link
                        :href="show(row.id).url"
                        class="font-medium hover:underline focus-visible:underline"
                        @click.stop
                        >{{ row.name }}</Link
                    >
                    <p
                        class="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <span v-if="row.email" class="truncate">{{
                            row.email
                        }}</span>
                        <span v-if="row.country">· {{ row.country }}</span>
                        <span>· {{ row.currency }}</span>
                        <StatusBadge
                            v-if="row.reminders_paused"
                            tone="warning"
                            :icon="CirclePause"
                            :dot="false"
                            :label="
                                row.reminders_pause_source ===
                                'recipient_opt_out'
                                    ? t('pf.client.opted_out')
                                    : t('pf.client.paused')
                            "
                        />
                    </p>
                </div>
            </template>
            <template #cell-class="{ row }">
                <ClientClassBadge :value="row.late_payer_class" />
            </template>
            <template #cell-avg_days_late="{ row }">
                <span class="tabular-nums">{{
                    row.paid_invoices_count ? pf.days(row.avg_days_late) : '—'
                }}</span>
            </template>
            <template #cell-open_balance="{ row }">
                <span class="font-medium tabular-nums">{{
                    pf.money(row.open_balance)
                }}</span>
                <p class="text-xs text-muted-foreground">
                    {{
                        t('pf.client.open_count', {
                            count: row.open_invoices_count,
                        })
                    }}
                </p>
            </template>
            <template #cell-overdue_balance="{ row }">
                <span
                    :class="[
                        'tabular-nums',
                        row.overdue_balance.minor > 0
                            ? 'font-medium text-destructive'
                            : 'text-muted-foreground',
                    ]"
                    >{{ pf.money(row.overdue_balance) }}</span
                >
            </template>
            <template #cell-owner="{ row }">
                <span class="text-muted-foreground">{{
                    row.owner?.name ?? '—'
                }}</span>
            </template>
            <template #empty>
                <EmptyState
                    :icon="active() ? SearchX : Building2"
                    :tone="active() ? 'info' : 'primary'"
                    :title="
                        active()
                            ? t('pf.clients.empty_filtered')
                            : t('pf.clients.empty_title')
                    "
                    :description="
                        active()
                            ? t('ui.common.try_other_filters')
                            : t('pf.clients.empty_body')
                    "
                >
                    <Button v-if="active()" variant="outline" @click="reset">{{
                        t('ui.common.clear_filters')
                    }}</Button>
                    <div
                        v-else-if="pf.can('clients.manage')"
                        class="flex flex-wrap justify-center gap-2"
                    >
                        <Button as-child>
                            <Link :href="create().url">
                                <Plus class="size-4" aria-hidden="true" />
                                {{ t('pf.clients.create') }}
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
                <LaravelPagination
                    :links="clients.links"
                    :meta="clients.meta"
                    :count="clients.data.length"
                    :only="['clients', 'filters', 'class_counts']"
                />
            </template>
        </DataTable>
    </div>
</template>
