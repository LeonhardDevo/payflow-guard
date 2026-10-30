<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CircleCheck,
    FileText,
    FilterX,
    Gauge,
    History,
    MessageSquareText,
    MessagesSquare,
    SearchX,
    Send,
    Globe,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfidenceMeter from '@/components/assistant/ConfidenceMeter.vue';
import type { DataColumn } from '@/components/common/DataTable.vue';
import DataTable from '@/components/common/DataTable.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import KpiCard from '@/components/common/KpiCard.vue';
import LaravelPagination from '@/components/common/LaravelPagination.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import RelativeTime from '@/components/common/RelativeTime.vue';
import SearchInput from '@/components/common/SearchInput.vue';
import SegmentedControl from '@/components/common/SegmentedControl.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useFilters } from '@/composables/useFilters';
import { useFormat } from '@/composables/useFormat';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import type {
    AiChannel,
    AiQuery,
    AiQueryFilters,
    AiQueryStats,
    Paginated,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'ui.nav.assistant', href: endpoints.assistant.index() },
            { title: 'ui.nav.history', href: endpoints.assistant.history() },
        ],
    },
});

const props = defineProps<{
    queries: Paginated<AiQuery>;
    filters: AiQueryFilters;
    stats: AiQueryStats;
}>();

const fmt = useFormat();

const { filters, reset, active } = useFilters(
    () => endpoints.assistant.history().url,
    {
        q: props.filters?.q ?? null,
        status: props.filters?.status ?? null,
        channel: props.filters?.channel ?? null,
    },
    ['queries', 'filters', 'stats'],
);

const statusModel = computed({
    get: () => (filters.status ?? 'all') as 'all' | 'answered' | 'refused',
    set: (value) => {
        filters.status = value === 'all' ? null : value;
    },
});

const channelModel = computed({
    get: () => (filters.channel ?? 'all') as string,
    set: (value: string) => {
        filters.channel = value === 'all' ? null : (value as AiChannel);
    },
});

const statusOptions = computed(() => [
    { value: 'all' as const, label: t('ui.common.all') },
    { value: 'answered' as const, label: t('ui.history.answered') },
    { value: 'refused' as const, label: t('ui.history.refused') },
]);

const columns = computed<DataColumn<AiQuery>[]>(() => [
    { id: 'question', header: t('ui.history.question') },
    { id: 'refused', header: t('ui.common.status'), sortable: true },
    {
        id: 'confidence',
        header: t('ui.assistant.confidence'),
        sortable: true,
        hideBelow: 'md',
    },
    { id: 'channel', header: t('ui.history.channel'), hideBelow: 'lg' },
    {
        id: 'user',
        header: t('ui.history.user'),
        accessor: (row) => row.user?.name ?? '',
        hideBelow: 'lg',
    },
    {
        id: 'created_at',
        header: t('ui.history.when'),
        sortable: true,
        hideBelow: 'sm',
    },
]);

const selected = ref<AiQuery | null>(null);
const detailOpen = computed({
    get: () => selected.value !== null,
    set: (value) => {
        if (!value) {
            selected.value = null;
        }
    },
});

const answeredRate = computed(() =>
    props.stats.total ? props.stats.answered / props.stats.total : 0,
);
</script>

<template>
    <Head :title="t('ui.history.title')" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <PageHeader
            :icon="History"
            :title="t('ui.history.title')"
            :description="t('ui.history.description')"
        >
            <template #actions>
                <Button as-child>
                    <Link :href="endpoints.assistant.index()">
                        <MessageSquareText class="size-4" aria-hidden="true" />
                        {{ t('ui.history.open_assistant') }}
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <section
            class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4"
            :aria-label="t('ui.history.stats')"
        >
            <KpiCard
                :label="t('ui.history.total')"
                :value="stats.total"
                :icon="MessagesSquare"
                tone="info"
            />
            <KpiCard
                :label="t('ui.history.answered')"
                :value="stats.answered"
                :icon="CircleCheck"
                :hint="
                    t('ui.history.answered_rate', {
                        rate: fmt.percent(answeredRate),
                    })
                "
            />
            <KpiCard
                :label="t('ui.history.refused')"
                :value="stats.refused"
                :icon="SearchX"
                tone="warning"
                :hint="t('ui.history.refused_hint')"
            />
            <KpiCard
                :label="t('ui.history.avg_confidence')"
                :value="stats.avg_confidence * 100"
                :format="(n: number) => fmt.percent(n / 100)"
                :icon="Gauge"
                tone="ai"
            />
        </section>

        <section class="space-y-4" :aria-label="t('ui.history.log')">
            <div
                class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"
            >
                <SearchInput
                    v-model="filters.q"
                    :placeholder="t('ui.history.search')"
                />
                <div class="flex flex-wrap items-center gap-2">
                    <SegmentedControl
                        v-model="statusModel"
                        :options="statusOptions"
                        :label="t('ui.common.status')"
                    />
                    <Select v-model="channelModel">
                        <SelectTrigger
                            class="w-40"
                            :aria-label="t('ui.history.channel')"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">{{
                                t('ui.history.all_channels')
                            }}</SelectItem>
                            <SelectItem value="web">{{
                                t('ui.history.channels.web')
                            }}</SelectItem>
                            <SelectItem value="telegram">{{
                                t('ui.history.channels.telegram')
                            }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <Button
                        v-if="active()"
                        variant="ghost"
                        size="sm"
                        @click="reset"
                    >
                        <FilterX class="size-4" aria-hidden="true" />
                        {{ t('ui.common.clear_filters') }}
                    </Button>
                </div>
            </div>

            <DataTable
                :columns="columns"
                :rows="queries.data"
                :row-key="(row) => row.id"
                :caption="t('ui.history.log')"
                clickable
                @row-click="(row) => (selected = row)"
            >
                <template #cell-question="{ row }">
                    <div class="max-w-md min-w-48">
                        <p class="line-clamp-1 font-medium">
                            {{ row.question }}
                        </p>
                        <p class="line-clamp-1 text-xs text-muted-foreground">
                            {{ row.answer ?? '—' }}
                        </p>
                    </div>
                </template>
                <template #cell-refused="{ row }">
                    <StatusBadge
                        :tone="row.refused ? 'warning' : 'success'"
                        :label="
                            row.refused
                                ? t('ui.history.refused')
                                : t('ui.history.answered')
                        "
                    />
                </template>
                <template #cell-confidence="{ row }">
                    <ConfidenceMeter :value="row.confidence" />
                </template>
                <template #cell-channel="{ row }">
                    <span
                        class="inline-flex items-center gap-1.5 text-muted-foreground"
                    >
                        <component
                            :is="row.channel === 'telegram' ? Send : Globe"
                            class="size-3.5"
                            aria-hidden="true"
                        />
                        {{ t(`ui.history.channels.${row.channel}`) }}
                    </span>
                </template>
                <template #cell-user="{ row }">
                    <span class="text-muted-foreground">{{
                        row.user?.name ?? t('ui.history.anonymous')
                    }}</span>
                </template>
                <template #cell-created_at="{ row }">
                    <span class="text-muted-foreground"
                        ><RelativeTime :value="row.created_at"
                    /></span>
                </template>
                <template #empty>
                    <EmptyState
                        :icon="active() ? SearchX : MessagesSquare"
                        tone="ai"
                        :title="
                            active()
                                ? t('ui.history.empty_filtered')
                                : t('ui.history.empty_title')
                        "
                        :description="
                            active()
                                ? t('ui.common.try_other_filters')
                                : t('ui.history.empty_body')
                        "
                    >
                        <Button
                            v-if="active()"
                            variant="outline"
                            @click="reset"
                            >{{ t('ui.common.clear_filters') }}</Button
                        >
                        <Button v-else as-child>
                            <Link :href="endpoints.assistant.index()">{{
                                t('ui.history.open_assistant')
                            }}</Link>
                        </Button>
                    </EmptyState>
                </template>
                <template #footer>
                    <LaravelPagination
                        :links="queries.links"
                        :meta="queries.meta"
                        :count="queries.data.length"
                        :only="['queries', 'filters', 'stats']"
                    />
                </template>
            </DataTable>
        </section>
    </div>

    <Sheet v-model:open="detailOpen">
        <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-xl">
            <SheetHeader v-if="selected">
                <SheetTitle>{{ t('ui.history.detail_title') }}</SheetTitle>
                <SheetDescription>
                    <RelativeTime :value="selected.created_at" /> ·
                    {{ t(`ui.history.channels.${selected.channel}`) }}
                    <template v-if="selected.user">
                        · {{ selected.user.name }}</template
                    >
                </SheetDescription>
            </SheetHeader>
            <div v-if="selected" class="space-y-5 px-4 pb-6">
                <div
                    class="rounded-2xl bg-primary px-4 py-3 text-primary-foreground"
                >
                    <p class="text-xs font-semibold uppercase opacity-80">
                        {{ t('ui.history.question') }}
                    </p>
                    <p class="mt-1 whitespace-pre-wrap">
                        {{ selected.question }}
                    </p>
                </div>
                <div class="rounded-2xl border bg-card px-4 py-3">
                    <div class="flex items-center justify-between gap-2">
                        <p
                            class="text-xs font-semibold text-muted-foreground uppercase"
                        >
                            {{ t('ui.history.answer') }}
                        </p>
                        <StatusBadge
                            :tone="selected.refused ? 'warning' : 'success'"
                            :label="
                                selected.refused
                                    ? t('ui.history.refused')
                                    : t('ui.history.answered')
                            "
                        />
                    </div>
                    <p class="mt-2 text-sm leading-relaxed whitespace-pre-wrap">
                        {{ selected.answer ?? '—' }}
                    </p>
                </div>
                <div v-if="selected.citations.length" class="space-y-2">
                    <p
                        class="text-xs font-semibold text-muted-foreground uppercase"
                    >
                        {{ t('ui.assistant.sources') }}
                    </p>
                    <ul class="space-y-1.5">
                        <li
                            v-for="(citation, index) in selected.citations"
                            :key="index"
                        >
                            <Link
                                :href="
                                    endpoints.knowledge.show(
                                        citation.document_id,
                                    )
                                "
                                class="flex items-center gap-2 rounded-lg border p-2.5 text-sm transition-colors hover:bg-muted"
                            >
                                <span
                                    class="inline-flex size-5 items-center justify-center rounded-full bg-ai text-[0.625rem] font-bold text-ai-foreground"
                                    >{{ index + 1 }}</span
                                >
                                <FileText
                                    class="size-4 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 flex-1 truncate">{{
                                    citation.title
                                }}</span>
                                <span
                                    v-if="citation.page"
                                    class="text-xs text-muted-foreground"
                                    >{{
                                        t('ui.assistant.page_short', {
                                            page: citation.page,
                                        })
                                    }}</span
                                >
                            </Link>
                        </li>
                    </ul>
                </div>
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-xl bg-muted/60 p-3">
                        <dt class="text-xs text-muted-foreground">
                            {{ t('ui.assistant.confidence') }}
                        </dt>
                        <dd class="mt-1">
                            <ConfidenceMeter :value="selected.confidence" />
                        </dd>
                    </div>
                    <div class="rounded-xl bg-muted/60 p-3">
                        <dt class="text-xs text-muted-foreground">
                            {{ t('ui.history.latency') }}
                        </dt>
                        <dd class="mt-1 font-semibold tabular-nums">
                            {{ fmt.duration(selected.latency_ms) }}
                        </dd>
                    </div>
                    <div class="rounded-xl bg-muted/60 p-3">
                        <dt class="text-xs text-muted-foreground">
                            {{ t('ui.history.model') }}
                        </dt>
                        <dd class="mt-1 truncate font-mono text-xs">
                            {{ selected.provider ?? '—' }} /
                            {{ selected.model ?? '—' }}
                        </dd>
                    </div>
                    <div class="rounded-xl bg-muted/60 p-3">
                        <dt class="text-xs text-muted-foreground">
                            {{ t('ui.history.tools') }}
                        </dt>
                        <dd class="mt-1 truncate font-mono text-xs">
                            {{ selected.tools_used.join(', ') || '—' }}
                        </dd>
                    </div>
                </dl>
            </div>
        </SheetContent>
    </Sheet>
</template>
