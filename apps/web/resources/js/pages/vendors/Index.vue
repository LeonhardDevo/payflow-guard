<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Download, FilterX, Info, Plus, SearchX, Store, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { DataColumn } from '@/components/common/DataTable.vue';
import DataTable from '@/components/common/DataTable.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import LaravelPagination from '@/components/common/LaravelPagination.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SearchInput from '@/components/common/SearchInput.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import GuardBand from '@/components/pf/guard/GuardBand.vue';
import GuardErrorDialog from '@/components/pf/guard/GuardErrorDialog.vue';
import StepUpDialog from '@/components/pf/guard/StepUpDialog.vue';
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
import { useFilters } from '@/composables/useFilters';
import { useFormat } from '@/composables/useFormat';
import { usePf } from '@/composables/pf/usePf';
import { useStepUp } from '@/composables/pf/useStepUp';
import { t } from '@/lib/i18n';
import { BANK_STATUS_TONE, VENDOR_STATUS_TONE } from '@/lib/pf/status';
import { create, index, show } from '@/routes/vendors';
import { unmasked } from '@/routes/vendors/export';
import { dismiss } from '@/routes/vendors/notice';
import type {
    VendorCategory,
    VendorListItem,
    VendorStatus,
    VendorsIndexProps,
} from '@/types/payflow-guard';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'pf.nav.vendors', href: index() }],
    },
});

const props = defineProps<VendorsIndexProps>();
const pf = usePf();
const fmt = useFormat();
const stepUp = useStepUp();
const page = usePage();
const isOwner = computed(() => page.props.workspace?.role === 'owner');

const { filters, reset, active } = useFilters(
    () => index().url,
    {
        q: props.filters.q,
        status: props.filters.status,
        category: props.filters.category,
        attention: props.filters.attention,
    } as {
        q: string | null;
        status: string | null;
        category: string | null;
        attention: string | null;
    },
    ['vendors', 'filters', 'counts'],
);

const statuses: VendorStatus[] = ['active', 'on_hold', 'archived'];
const categories: VendorCategory[] = [
    'contractor',
    'freelancer',
    'agency',
    'saas',
    'other',
];

const model = (key: 'status' | 'category') =>
    computed({
        get: () => filters[key] ?? 'all',
        set: (value: string) => {
            filters[key] = value === 'all' ? null : value;
        },
    });
const statusModel = model('status');
const categoryModel = model('category');

const chips = computed(() => [
    { value: null, label: t('ui.common.all'), count: props.counts.all },
    {
        value: 'pending_change',
        label: t('guard.vendors.pending_change'),
        count: props.counts.pending_change,
    },
    {
        value: 'on_hold',
        label: t('guard.vendors.on_hold'),
        count: props.counts.on_hold,
    },
]);

const noticeVisible = ref(props.processing_notice);

function dismissNotice(): void {
    noticeVisible.value = false;
    router.post(
        dismiss().url,
        {},
        { preserveScroll: true, preserveState: true },
    );
}

function exportUnmasked(): void {
    stepUp.run(() => {
        window.location.href = unmasked().url;
    }, true);
}

const columns = computed<DataColumn<VendorListItem>[]>(() => [
    { id: 'name', header: t('guard.vendor.name') },
    { id: 'account', header: t('guard.vendor.bank_account'), hideBelow: 'sm' },
    { id: 'attention', header: t('guard.vendors.attention'), hideBelow: 'md' },
    {
        id: 'payouts',
        header: t('guard.vendors.payouts'),
        align: 'right',
        hideBelow: 'lg',
    },
]);
</script>

<template>
    <Head :title="t('guard.vendors.title')" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <GuardBand />
        <PageHeader
            :icon="Store"
            :title="t('guard.vendors.title')"
            :description="t('guard.vendors.description')"
        >
            <template #actions>
                <Button
                    v-if="isOwner"
                    variant="outline"
                    data-test="vendors-export"
                    @click="exportUnmasked"
                >
                    <Download class="size-4" aria-hidden="true" />
                    {{ t('guard.vendors.export_unmasked') }}
                </Button>
                <Button v-if="pf.can('vendors.manage')" as-child>
                    <Link :href="create().url" data-test="vendor-create">
                        <Plus class="size-4" aria-hidden="true" />
                        {{ t('guard.vendors.create') }}
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <div
            v-if="noticeVisible"
            class="flex items-start gap-3 rounded-2xl border border-info/30 bg-info-soft p-4 text-sm text-info-soft-foreground"
            role="note"
            data-test="vendor-processing-notice"
        >
            <Info class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <p class="flex-1">{{ t('guard.vendors.processing_notice') }}</p>
            <Button
                size="icon"
                variant="ghost"
                class="size-8 shrink-0"
                :aria-label="t('ui.common.close')"
                @click="dismissNotice"
            >
                <X class="size-4" aria-hidden="true" />
            </Button>
        </div>

        <div
            class="flex flex-wrap gap-2"
            role="group"
            :aria-label="t('guard.vendors.attention')"
        >
            <button
                v-for="chip in chips"
                :key="chip.value ?? 'all'"
                type="button"
                :class="[
                    'inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    filters.attention === chip.value
                        ? 'border-primary bg-primary/10 text-foreground'
                        : 'bg-card text-muted-foreground hover:text-foreground',
                ]"
                :aria-pressed="filters.attention === chip.value"
                @click="filters.attention = chip.value"
            >
                {{ chip.label }}
                <span
                    :class="[
                        'rounded-full px-1.5 text-xs tabular-nums',
                        chip.value && chip.count
                            ? 'bg-warning-soft text-warning-soft-foreground'
                            : 'bg-muted',
                    ]"
                    >{{ chip.count }}</span
                >
            </button>
        </div>

        <section
            class="grid grid-cols-1 gap-3 rounded-2xl border bg-card p-4 shadow-soft sm:grid-cols-[1.5fr_1fr_1fr_auto] sm:items-end"
            :aria-label="t('ui.common.filters')"
        >
            <div class="grid gap-1.5">
                <span class="text-xs text-muted-foreground">{{
                    t('ui.common.search')
                }}</span>
                <SearchInput
                    v-model="filters.q"
                    :placeholder="t('guard.vendors.search')"
                    :label="t('guard.vendors.search')"
                    class="sm:max-w-none"
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="v-status" class="text-xs text-muted-foreground">{{
                    t('guard.fields.status')
                }}</Label>
                <Select v-model="statusModel">
                    <SelectTrigger id="v-status" class="w-full"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">{{
                            t('ui.common.all')
                        }}</SelectItem>
                        <SelectItem
                            v-for="status in statuses"
                            :key="status"
                            :value="status"
                            >{{
                                t(`guard.vendor_status.${status}`)
                            }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
            <div class="grid gap-1.5">
                <Label for="v-category" class="text-xs text-muted-foreground">{{
                    t('guard.vendor.category')
                }}</Label>
                <Select v-model="categoryModel">
                    <SelectTrigger id="v-category" class="w-full"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">{{
                            t('ui.common.all')
                        }}</SelectItem>
                        <SelectItem
                            v-for="category in categories"
                            :key="category"
                            :value="category"
                            >{{
                                t(`guard.vendor_category.${category}`)
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
            :rows="vendors.data"
            :row-key="(row) => row.id"
            :row-attrs="
                (row) => ({
                    'data-test': 'vendor-row',
                    'data-status': row.status,
                })
            "
            :caption="t('guard.vendors.title')"
            clickable
            @row-click="(row) => router.visit(show(row.id).url)"
        >
            <template #cell-name="{ row }">
                <Link
                    :href="show(row.id).url"
                    class="font-medium hover:underline"
                    @click.stop
                    >{{ row.name }}</Link
                >
                <p
                    class="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <span>{{
                        t(`guard.vendor_category.${row.category}`)
                    }}</span>
                    <span v-if="row.country">· {{ row.country }}</span>
                    <StatusBadge
                        v-if="row.status !== 'active'"
                        :tone="VENDOR_STATUS_TONE[row.status]"
                        :label="t(`guard.vendor_status.${row.status}`)"
                    />
                </p>
            </template>
            <template #cell-account="{ row }">
                <template v-if="row.current_account">
                    <span class="font-mono text-xs">{{
                        row.current_account.display
                    }}</span>
                    <p class="mt-0.5 flex items-center gap-1.5">
                        <StatusBadge
                            :tone="BANK_STATUS_TONE[row.current_account.status]"
                            :label="
                                t(
                                    `guard.bank.status.${row.current_account.status}`,
                                )
                            "
                        />
                        <span class="text-xs text-muted-foreground"
                            >{{ row.current_account.bank_country }} ·
                            {{ row.current_account.currency }}</span
                        >
                    </p>
                </template>
                <span v-else class="text-xs text-muted-foreground">{{
                    t('guard.vendor.no_account')
                }}</span>
            </template>
            <template #cell-attention="{ row }">
                <span
                    v-if="row.pending_change"
                    class="flex flex-col items-start gap-1"
                >
                    <span class="text-xs">{{
                        t('guard.vendors.change_requested', {
                            date: fmt.date(row.pending_change.requested_at),
                        })
                    }}</span>
                    <RiskBadge
                        :band="row.pending_change.risk_band"
                        :score="row.pending_change.risk_score"
                    />
                </span>
                <span v-else class="text-xs text-muted-foreground">—</span>
            </template>
            <template #cell-payouts="{ row }">
                <span class="tabular-nums">{{ row.open_payouts_count }}</span>
                <p
                    v-if="row.last_released_at"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        t('guard.vendors.last_paid', {
                            date: fmt.date(row.last_released_at),
                        })
                    }}
                </p>
            </template>
            <template #empty>
                <EmptyState
                    :icon="active() ? SearchX : Store"
                    :tone="active() ? 'info' : 'primary'"
                    :title="
                        active()
                            ? t('guard.vendors.empty_filtered')
                            : t('guard.vendors.empty_title')
                    "
                    :description="
                        active()
                            ? t('ui.common.try_other_filters')
                            : t('guard.vendors.empty_body')
                    "
                >
                    <Button v-if="active()" variant="outline" @click="reset">{{
                        t('ui.common.clear_filters')
                    }}</Button>
                    <Button v-else-if="pf.can('vendors.manage')" as-child>
                        <Link :href="create().url"
                            ><Plus class="size-4" aria-hidden="true" />{{
                                t('guard.vendors.create')
                            }}</Link
                        >
                    </Button>
                </EmptyState>
            </template>
            <template #footer>
                <LaravelPagination
                    :links="vendors.links"
                    :meta="vendors.meta"
                    :count="vendors.data.length"
                    :only="['vendors', 'filters', 'counts']"
                />
            </template>
        </DataTable>
    </div>

    <StepUpDialog />
    <GuardErrorDialog />
</template>
