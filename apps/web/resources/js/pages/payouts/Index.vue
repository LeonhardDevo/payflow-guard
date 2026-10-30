<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { FilterX, HandCoins, Plus, SearchX } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/common/EmptyState.vue';
import LaravelPagination from '@/components/common/LaravelPagination.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SearchableSelect from '@/components/common/SearchableSelect.vue';
import SearchInput from '@/components/common/SearchInput.vue';
import GuardBand from '@/components/pf/guard/GuardBand.vue';
import GuardErrorDialog from '@/components/pf/guard/GuardErrorDialog.vue';
import PayoutRow from '@/components/pf/guard/PayoutRow.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useFilters } from '@/composables/useFilters';
import { usePf } from '@/composables/pf/usePf';
import { t } from '@/lib/i18n';
import { create, index } from '@/routes/payouts';
import type { PayoutTab, PayoutsIndexProps } from '@/types/payflow-guard';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'pf.nav.payouts', href: index() }],
    },
});

const props = defineProps<PayoutsIndexProps>();
const pf = usePf();

const { filters, reset } = useFilters(
    () => index().url,
    {
        tab: props.tab,
        vendor_id: props.filters.vendor_id
            ? String(props.filters.vendor_id)
            : null,
        q: props.filters.q,
    } as { tab: string | null; vendor_id: string | null; q: string | null },
    ['payouts', 'counts', 'filters', 'tab'],
);

const tabs = computed<PayoutTab[]>(() =>
    pf.can('payouts.approve')
        ? ['awaiting_me', 'pending', 'approved', 'on_hold', 'released', 'all']
        : ['pending', 'approved', 'on_hold', 'released', 'all'],
);
const currentTab = computed(() => (filters.tab ?? props.tab) as PayoutTab);
const narrowed = () => Boolean(filters.vendor_id || filters.q);

function clear(): void {
    const tab = filters.tab;
    reset();
    filters.tab = tab;
}

const vendorOptions = computed(() =>
    props.vendors.map((v) => ({ value: String(v.value), label: v.label })),
);
</script>

<template>
    <Head :title="t('guard.payouts.title')" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <GuardBand />
        <PageHeader
            :icon="HandCoins"
            :title="t('guard.payouts.title')"
            :description="t('guard.payouts.description')"
        >
            <template v-if="pf.can('payouts.create')" #actions>
                <Button as-child>
                    <Link :href="create().url" data-test="payout-create">
                        <Plus class="size-4" aria-hidden="true" />
                        {{ t('guard.payouts.create') }}
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <nav
            class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0"
            :aria-label="t('guard.payouts.title')"
        >
            <ul class="flex min-w-max gap-1 border-b">
                <li v-for="tab in tabs" :key="tab">
                    <button
                        type="button"
                        :class="[
                            '-mb-px inline-flex items-center gap-2 border-b-2 px-3 py-2 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            currentTab === tab
                                ? 'border-primary font-medium text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        ]"
                        :aria-current="currentTab === tab ? 'page' : undefined"
                        :data-test="`payout-tab-${tab}`"
                        @click="filters.tab = tab"
                    >
                        {{ t(`guard.payouts.tabs.${tab}`) }}
                        <span
                            :class="[
                                'rounded-full px-1.5 text-xs tabular-nums',
                                tab === 'awaiting_me' && counts.awaiting_me
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-muted text-muted-foreground',
                            ]"
                            >{{ counts[tab] ?? 0 }}</span
                        >
                    </button>
                </li>
            </ul>
        </nav>

        <section
            class="grid grid-cols-1 gap-3 rounded-2xl border bg-card p-4 shadow-soft sm:grid-cols-[1.5fr_1fr_auto] sm:items-end"
            :aria-label="t('ui.common.filters')"
        >
            <div class="grid gap-1.5">
                <span class="text-xs text-muted-foreground">{{
                    t('ui.common.search')
                }}</span>
                <SearchInput
                    v-model="filters.q"
                    :placeholder="t('guard.payouts.search')"
                    :label="t('guard.payouts.search')"
                    class="sm:max-w-none"
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="po-vendor" class="text-xs text-muted-foreground">{{
                    t('guard.payout.vendor')
                }}</Label>
                <SearchableSelect
                    id="po-vendor"
                    v-model="filters.vendor_id"
                    :options="vendorOptions"
                    clearable
                    :placeholder="t('guard.payouts.any_vendor')"
                />
            </div>
            <Button variant="ghost" :disabled="!narrowed()" @click="clear">
                <FilterX class="size-4" aria-hidden="true" />
                {{ t('ui.common.clear_filters') }}
            </Button>
        </section>

        <section
            class="overflow-hidden rounded-2xl border bg-card shadow-soft"
            :aria-label="t(`guard.payouts.tabs.${currentTab}`)"
        >
            <EmptyState
                v-if="!payouts.data.length"
                :icon="narrowed() ? SearchX : HandCoins"
                :tone="narrowed() ? 'info' : 'primary'"
                :title="
                    narrowed()
                        ? t('guard.payouts.empty_filtered')
                        : t(`guard.payouts.empty.${currentTab}`)
                "
                :description="
                    narrowed()
                        ? t('ui.common.try_other_filters')
                        : t('guard.payouts.empty_body')
                "
            >
                <Button v-if="narrowed()" variant="outline" @click="clear">{{
                    t('ui.common.clear_filters')
                }}</Button>
                <Button v-else-if="pf.can('payouts.create')" as-child>
                    <Link :href="create().url"
                        ><Plus class="size-4" aria-hidden="true" />{{
                            t('guard.payouts.create')
                        }}</Link
                    >
                </Button>
            </EmptyState>
            <ul v-else class="divide-y">
                <PayoutRow
                    v-for="payout in payouts.data"
                    :key="payout.id"
                    :payout="payout"
                />
            </ul>
            <div v-if="payouts.data.length" class="border-t px-4 py-3">
                <LaravelPagination
                    :links="payouts.links"
                    :meta="payouts.meta"
                    :count="payouts.data.length"
                    :only="['payouts', 'counts', 'filters', 'tab']"
                />
            </div>
        </section>
    </div>

    <GuardErrorDialog />
</template>
